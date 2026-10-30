#!/usr/bin/env python3
"""Manually pull an encrypted recovery set and verify its manifest on the Mac.

No plaintext archive is written/extracted. Closed key never leaves the Mac.
Sudo password is prompted only for the current read and is never saved.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import selectors
import shlex
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import time


MAX_BYTES = 4 * 1024**3


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(1024*1024), b''):
            h.update(chunk)
    return h.hexdigest()


def safe_member(name):
    p = PurePosixPath(name)
    if p.is_absolute() or '..' in p.parts or not p.parts or p.parts[0] != 'arm-backup':
        raise ValueError('Unsafe archive member')
    return p


def verify_archive(path, identity, age='age'):
    proc = subprocess.Popen([age, '--decrypt', '-i', str(identity), str(path)],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    found = {}
    seen = set()
    manifest = None
    restore_report = None
    total = 0
    try:
        with tarfile.open(fileobj=proc.stdout, mode='r|gz') as archive:
            for member in archive:
                name = safe_member(member.name)
                if name.as_posix() in seen:
                    raise ValueError('Duplicate archive member')
                seen.add(name.as_posix())
                if len(seen) > 100000:
                    raise ValueError('Archive member limit exceeded')
                if member.isdir():
                    continue
                if not member.isfile() or len(name.parts) < 2:
                    raise ValueError('Only regular recovery-set files are accepted')
                if not 0 <= member.size <= MAX_BYTES:
                    raise ValueError('Invalid archive member size')
                total += member.size
                if total > MAX_BYTES or len(seen) > 100000:
                    raise ValueError('Archive size/member limit exceeded')
                relative = PurePosixPath(*name.parts[1:]).as_posix()
                content = archive.extractfile(member)
                h = hashlib.sha256()
                size = 0
                captured = bytearray()
                capture = relative in ['manifest.json', 'postgres-restore-report.json']
                if capture and member.size > 8*1024*1024:
                    raise ValueError('Oversized manifest/report')
                while chunk := content.read(1024*1024):
                    size += len(chunk)
                    h.update(chunk)
                    if capture:
                        captured.extend(chunk)
                if size != member.size:
                    raise ValueError('Truncated archive member')
                if relative == 'manifest.json':
                    manifest = json.loads(captured)
                else:
                    found[relative] = {'bytes': size, 'sha256': h.hexdigest()}
                if relative == 'postgres-restore-report.json':
                    restore_report = json.loads(captured)
        # age authentication is complete only after reading to EOF and checking exit.
        drained = 0
        while chunk := proc.stdout.read(65536):
            drained += len(chunk)
            if drained > MAX_BYTES:
                raise ValueError('Oversized archive trailer')
        error = proc.stderr.read()
        if proc.wait(timeout=30):
            raise ValueError('age authentication/decryption failed')
        if not manifest or manifest.get('format') != 'arm-recovery-set-v1':
            raise ValueError('Missing/unsupported recovery manifest')
        if manifest.get('files') != found:
            raise ValueError('Archive members do not match manifest hashes/sizes')
        required = {'postgres.dump', 'postgres-roles.sql', 'objects.json', 'configuration.tar',
                    'source.tar', 'public.tar', 'containers.json', 'postgres-restore-report.json'}
        if not required <= found.keys():
            raise ValueError('Required recovery components missing')
        if not restore_report or restore_report.get('result') != 'PASS':
            raise ValueError('Missing successful PostgreSQL restore report')
        if restore_report['restores'][0]['sha256'] != found['postgres.dump']['sha256']:
            raise ValueError('PostgreSQL restore report refers to a different dump')
        return {'files': len(found), 'plaintext_bytes': total,
                'commit': manifest['commit'], 'objects': manifest['objects'],
                'storage_files': manifest['storage_files'],
                'analytics_tables': len(manifest['analytics_tables']),
                'postgres_restore_test': 'PASS', 'consistency': manifest['consistency']}
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()
        proc.stdout.close()
        proc.stderr.close()


def private_directory(path):
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise ValueError('Private directory must be owned by current user, mode 0700')


def pull_range(args, password, start, length, destination):
    program = ("import pathlib,sys; p=pathlib.Path("+repr(args.source)+
        "); assert p.is_file() and not p.is_symlink() and p.stat().st_size=="+str(args.bytes)+
        "; f=p.open('rb'); f.seek("+str(start)+"); remaining="+str(length)+"\n"
        "while remaining:\n"
        " chunk=f.read(min(1048576,remaining))\n"
        " if not chunk: raise RuntimeError('Truncated source')\n"
        " sys.stdout.buffer.write(chunk); remaining-=len(chunk)\n")
    command = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'ForwardAgent=no', '-o', 'ForwardX11=no', '-o', 'ClearAllForwardings=yes',
        '-o', 'PermitLocalCommand=no', '-o', 'ControlPath=none', '-T',
        '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3',
        '-o', 'IdentitiesOnly=yes', '-i', str(args.ssh_identity), args.host,
        "sudo -S -p '' -- python3 -c "+shlex.quote(program)]
    with tempfile.TemporaryFile() as errors, destination.open('xb') as output:
        proc = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors)
        try:
            proc.stdin.write((password+'\n').encode())
            proc.stdin.close()
            received = 0
            deadline = time.monotonic()+args.timeout_seconds
            with selectors.DefaultSelector() as selector:
                selector.register(proc.stdout, selectors.EVENT_READ)
                while True:
                    if time.monotonic() > deadline:
                        raise TimeoutError('Range download exceeded timeout')
                    if not selector.select(timeout=1):
                        continue
                    chunk = os.read(proc.stdout.fileno(), 1024*1024)
                    if not chunk:
                        break
                    received += len(chunk)
                    if received > length:
                        raise ValueError('Range exceeds requested length')
                    output.write(chunk)
            if proc.wait(timeout=10) or received != length:
                raise ValueError('Incomplete SSH range')
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            proc.stdout.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source')
    parser.add_argument('--sha256')
    parser.add_argument('--bytes', type=int)
    parser.add_argument('--host', default='deploy@83.220.173.171')
    parser.add_argument('--ssh-identity', type=Path, default=Path.home()/'.ssh'/'id_ed25519')
    parser.add_argument('--age-identity', type=Path,
        default=Path.home()/'.tch15-arm'/'backup-keys'/'full-backup.agekey')
    parser.add_argument('--directory', type=Path, default=Path.home()/'.tch15-arm'/'backups'/'full')
    parser.add_argument('--verify-file', type=Path)
    parser.add_argument('--timeout-seconds', type=int, default=3600)
    parser.add_argument('--streams', type=int, choices=range(1, 5), default=1)
    args = parser.parse_args()
    os.umask(0o077)
    age = shutil.which('age')
    if not age:
        raise SystemExit('Install age before pulling/verifying full backup.')
    info = args.age_identity.lstat()
    assert stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid() and stat.S_IMODE(info.st_mode) == 0o600
    if args.verify_file:
        if args.sha256 and digest(args.verify_file) != args.sha256:
            raise ValueError('Ciphertext checksum mismatch')
        print(json.dumps(verify_archive(args.verify_file, args.age_identity, age)))
        return
    if not args.source or not re.fullmatch(r'/var/lib/arm-backups/arm-full-[0-9TZ]+\.tar\.gz\.age', args.source):
        raise SystemExit('Requires a completed root-owned encrypted recovery-set path.')
    if not args.sha256 or not re.fullmatch(r'[0-9a-f]{64}', args.sha256):
        raise SystemExit('Requires checksum from the private server receipt.')
    if not args.bytes or not 0 < args.bytes <= MAX_BYTES:
        raise SystemExit('Requires expected encrypted size, at most 4 GiB.')
    if not 60 <= args.timeout_seconds <= 7200:
        raise SystemExit('Transfer timeout must be between 60 and 7200 seconds.')
    private_directory(args.directory)
    lock = (args.directory/'pull.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    target = args.directory/Path(args.source).name
    assert not target.exists(), 'Existing completed copy must not be overwritten'
    temporary = target.with_suffix(target.suffix+'.partial')
    offset = 0
    if temporary.exists():
        info = temporary.lstat()
        assert stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid()
        assert stat.S_IMODE(info.st_mode) == 0o600 and info.st_size <= args.bytes
        offset = info.st_size
    assert shutil.disk_usage(args.directory).free > args.bytes-offset + 1024**3
    remote = ("import pathlib,sys; p=pathlib.Path("+repr(args.source)+
              "); assert p.is_file() and not p.is_symlink() and p.stat().st_size=="+str(args.bytes)+
              "; f=p.open('rb'); f.seek("+str(offset)+"); import shutil; shutil.copyfileobj(f,sys.stdout.buffer,1048576)")
    command = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'ForwardAgent=no', '-o', 'ForwardX11=no', '-o', 'ClearAllForwardings=yes',
        '-o', 'PermitLocalCommand=no', '-o', 'ControlPath=none', '-T',
        '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3',
        '-o', 'IdentitiesOnly=yes', '-i', str(args.ssh_identity), args.host,
        "sudo -S -p '' -- python3 -c "+shlex.quote(remote)]
    password = getpass.getpass('Server sudo password (not saved): ')
    try:
        if args.streams > 1 and offset < args.bytes:
            # Separate encrypted chunks are merged in order only after complete reads.
            assert shutil.disk_usage(args.directory).free > 2*(args.bytes-offset) + 1024**3
            with tempfile.TemporaryDirectory(prefix='encrypted-ranges-', dir=args.directory) as directory:
                size = (args.bytes-offset+args.streams-1)//args.streams
                ranges = [(start, min(size, args.bytes-start), Path(directory)/str(index))
                          for index, start in enumerate(range(offset, args.bytes, size))]
                with ThreadPoolExecutor(max_workers=args.streams) as pool:
                    futures = [pool.submit(pull_range, args, password, start, length, path)
                               for start, length, path in ranges]
                    for future in futures:
                        future.result()
                with temporary.open('ab' if offset else 'xb') as output:
                    for _, _, path in ranges:
                        with path.open('rb') as source:
                            shutil.copyfileobj(source, output, 1024*1024)
                    output.flush()
                    os.fsync(output.fileno())
            del password
        elif offset < args.bytes:
            with tempfile.TemporaryFile() as errors, temporary.open('ab' if offset else 'xb') as output:
                proc = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors)
                try:
                    proc.stdin.write((password+'\n').encode())
                    proc.stdin.close()
                    del password
                    received = offset
                    deadline = time.monotonic()+args.timeout_seconds
                    with selectors.DefaultSelector() as selector:
                        selector.register(proc.stdout, selectors.EVENT_READ)
                        while True:
                            if time.monotonic() > deadline:
                                raise TimeoutError('Backup read exceeded configured transfer timeout')
                            if not selector.select(timeout=1):
                                continue
                            chunk = os.read(proc.stdout.fileno(), 1024*1024)
                            if not chunk:
                                break
                            received += len(chunk)
                            if received > args.bytes:
                                raise ValueError('Remote output exceeds receipt size')
                            output.write(chunk)
                    if proc.wait(timeout=10) or received != args.bytes:
                        raise ValueError('SSH read failed or archive was truncated')
                    output.flush()
                    os.fsync(output.fileno())
                finally:
                    if proc.poll() is None:
                        proc.kill()
                        proc.wait()
                    proc.stdout.close()
        if digest(temporary) != args.sha256:
            raise ValueError('Ciphertext checksum mismatch')
        verified = verify_archive(temporary, args.age_identity, age)
        temporary.rename(target)
        receipt = dict(verified, copied_utc=datetime.now(timezone.utc).isoformat(),
                       archive=target.name, bytes=args.bytes, sha256=args.sha256)
        with target.with_suffix(target.suffix+'.verified.json').open('x') as output:
            json.dump(receipt, output, indent=2)
            output.write('\n')
        print('PC_RECOVERY_SET_DECRYPTION_AND_MANIFEST_VERIFIED')
        print('BACKUP='+str(target))
        print(json.dumps(verified))
    except BaseException:
        if temporary.exists():
            print('INCOMPLETE_ENCRYPTED_COPY_RETAINED='+str(temporary), file=sys.stderr)
        raise


if __name__ == '__main__':
    main()
