#!/usr/bin/env python3
"""Pull a previously verified small PostgreSQL dump to this computer encrypted.

Manual operation: sudo password is prompted and never saved. No remote writes,
plaintext local dump, scheduled job, retention deletion or full application backup.
Requires the existing Python cryptography package. Maximum dump size: 32 MiB.
"""
import argparse
from datetime import datetime, timezone
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import selectors
import shlex
import stat
import subprocess
import tempfile
import time
import uuid

from cryptography.fernet import Fernet


LIMIT = 32 * 1024 * 1024


def private_file(path, content):
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, default=Path.home()/'.tch15-arm'/'backups')
    parser.add_argument('--host', default='deploy@83.220.173.171')
    parser.add_argument('--identity', type=Path, default=Path.home()/'.ssh'/'id_ed25519')
    parser.add_argument('--source')
    parser.add_argument('--sha256')
    parser.add_argument('--verify-file', type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    base = args.directory.expanduser()
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = base.lstat()
    assert stat.S_ISDIR(info.st_mode) and info.st_uid == os.getuid()
    assert stat.S_IMODE(info.st_mode) == 0o700, 'Backup directory must have mode 0700'
    key = base/'recovery.key'
    if not key.exists():
        if args.verify_file:
            raise SystemExit('Recovery key missing; refusing to generate a different key.')
        private_file(key, Fernet.generate_key()+b'\n')
    info = key.lstat()
    assert stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid()
    assert stat.S_IMODE(info.st_mode) == 0o600
    cipher = Fernet(key.read_bytes().strip())
    if args.verify_file:
        assert args.verify_file.stat().st_size <= LIMIT*2
        data = cipher.decrypt(args.verify_file.read_bytes())
        assert data.startswith(b'PGDMP')
        digest = hashlib.sha256(data).hexdigest()
        if args.sha256:
            assert digest == args.sha256
        print('LOCAL_DECRYPTION_VERIFIED sha256='+digest)
        return
    if not args.source or not re.fullmatch(r'/root/arm-backup-check-[0-9TZ]+/postgres\.dump', args.source):
        raise SystemExit('Requires a verified /root/arm-backup-check-<stamp>/postgres.dump source.')
    if not args.sha256 or not re.fullmatch(r'[0-9a-f]{64}', args.sha256):
        raise SystemExit('Requires SHA-256 from the independently checked restore report.')
    password = getpass.getpass('Server sudo password (not saved): ')
    remote = ("import pathlib,sys; p=pathlib.Path("+repr(args.source)+
              "); assert p.is_file() and 0<p.stat().st_size<="+str(LIMIT)+
              "; sys.stdout.buffer.write(p.read_bytes())")
    command = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
               '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=10',
               '-o', 'ServerAliveCountMax=3', '-o', 'IdentitiesOnly=yes',
               '-i', str(args.identity), args.host,
               "sudo -S -p '' -- python3 -c "+shlex.quote(remote)]
    with tempfile.TemporaryFile() as errors:
        proc = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors)
        try:
            proc.stdin.write((password+'\n').encode())
            proc.stdin.close()
            del password
            data = bytearray()
            deadline = time.monotonic()+90
            with selectors.DefaultSelector() as selector:
                selector.register(proc.stdout, selectors.EVENT_READ)
                while True:
                    if time.monotonic() > deadline:
                        raise TimeoutError('Backup read exceeded 90 seconds')
                    if not selector.select(timeout=1):
                        continue
                    chunk = os.read(proc.stdout.fileno(), 65536)
                    if not chunk:
                        break
                    data.extend(chunk)
                    if len(data) > LIMIT:
                        raise RuntimeError('Remote output exceeds backup size limit')
            if proc.wait(timeout=5):
                raise RuntimeError('SSH/sudo backup read failed; no backup accepted')
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            proc.stdout.close()
    data = bytes(data)
    assert data.startswith(b'PGDMP') and hashlib.sha256(data).hexdigest() == args.sha256
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    target = base/('postgres-'+stamp+'-'+uuid.uuid4().hex[:8]+'.dump.fernet')
    token = cipher.encrypt(data)
    temporary = target.with_suffix(target.suffix+'.partial')
    private_file(temporary, token)
    assert hashlib.sha256(cipher.decrypt(temporary.read_bytes())).hexdigest() == args.sha256
    temporary.rename(target)
    manifest = {'copied_utc': stamp, 'source_host': args.host, 'source_path': args.source,
                'plaintext_bytes': len(data), 'plaintext_sha256': args.sha256,
                'encrypted_bytes': target.stat().st_size,
                'encrypted_sha256': hashlib.sha256(token).hexdigest(),
                'scope': 'PostgreSQL only, manual copy; not a full application backup'}
    private_file(target.with_suffix(target.suffix+'.json'),
                 (json.dumps(manifest, indent=2)+'\n').encode())
    print('ENCRYPTED_PC_POSTGRES_COPY_VERIFIED')
    print('BACKUP='+str(target))
    print('RECOVERY_KEY='+str(key))
    print('PLAINTEXT_BYTES='+str(len(data)))


if __name__ == '__main__':
    main()
