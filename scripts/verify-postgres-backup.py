#!/usr/bin/env python3
"""Create a read-only logical dump and restore old/fresh backups in isolation.

Root-only files. Never restores into production. No network, published ports,
bind mounts or persistent volumes on the disposable PostgreSQL container.
This is a local PostgreSQL recovery test, not a full/off-site application backup.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time


def run(*args, timeout=45, check=True, **kwargs):
    return subprocess.run(args, capture_output=True, check=check, timeout=timeout, **kwargs)


def text(*args):
    return run(*args).stdout.decode().strip()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run', action='store_true')
    parser.add_argument('--existing-dump', type=Path, required=True)
    args = parser.parse_args()
    if not args.run or os.geteuid() != 0:
        raise SystemExit('Requires root and explicit --run; no production restore is supported.')
    os.umask(0o077)
    existing = args.existing_dump.resolve(strict=True)
    assert existing.is_file() and existing.stat().st_size > 0
    assert shutil.disk_usage('/root').free > 1024**3, 'Require at least 1 GiB free'
    image = text('docker', 'inspect', '--format', '{{.Image}}', 'arm-postgres')
    uid = int(text('docker', 'exec', 'arm-postgres', 'id', '-u', 'postgres'))
    gid = int(text('docker', 'exec', 'arm-postgres', 'id', '-g', 'postgres'))
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/root') / ('arm-backup-check-' + stamp)
    backup.mkdir(mode=0o700)
    fresh = backup/'postgres.dump'
    # pg_dump takes a consistent snapshot, with a short wait for conflicting DDL.
    with fresh.open('xb') as output:
        result = subprocess.run(['docker', 'exec', 'arm-postgres', 'sh', '-c',
            'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" '
            '--format=custom --compress=6 --lock-wait-timeout=5s'],
            stdout=output, stderr=subprocess.PIPE, timeout=120)
    (backup/'dump.stderr').write_bytes(result.stderr)
    if result.returncode != 0:
        raise RuntimeError('Fresh dump failed; private stderr saved in '+str(backup))
    assert fresh.stat().st_size > 0
    report = {'started_utc': stamp, 'image': image, 'restores': [],
              'scope': 'local PostgreSQL only; no off-site or object-store recovery'}
    container = 'arm-backup-check-' + stamp.lower()
    assert run('docker', 'inspect', container, check=False).returncode != 0
    started = False
    try:
        run('docker', 'run', '-d', '--rm', '--name', container,
            '--network=none', '--read-only', '--user=postgres',
            '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
            '--memory=512m', '--cpus=0.75', '--pids-limit=128', '--shm-size=32m',
            '--tmpfs', f'/var/lib/postgresql/data:rw,nosuid,noexec,size=256m,uid={uid},gid={gid},mode=0700',
            '--tmpfs', f'/var/run/postgresql:rw,nosuid,noexec,size=8m,uid={uid},gid={gid},mode=0770',
            '--tmpfs', '/tmp:rw,nosuid,noexec,size=16m,mode=1777',
            '-e', 'POSTGRES_USER=restore_admin', '-e', 'POSTGRES_DB=restore_check',
            '-e', 'POSTGRES_HOST_AUTH_METHOD=trust', image,
            'postgres', '-c', 'shared_buffers=16MB', '-c', 'max_connections=10',
            '-c', 'temp_file_limit=65536', '-c', 'statement_timeout=90000',
            '-c', 'listen_addresses=')
        started = True
        inspected = json.loads(run('docker', 'inspect', container).stdout)[0]
        assert inspected['HostConfig']['NetworkMode'] == 'none'
        assert inspected['HostConfig']['ReadonlyRootfs']
        assert not inspected['HostConfig']['PortBindings']
        assert all(m['Type'] == 'tmpfs' for m in inspected['Mounts'])
        for _ in range(60):
            ready = run('docker', 'exec', container, 'pg_isready',
                        '-U', 'restore_admin', '-d', 'restore_check', check=False)
            if ready.returncode == 0 and text('docker', 'exec', container, 'cat', '/proc/1/comm') == 'postgres':
                break
            time.sleep(0.5)
        else:
            raise RuntimeError('Isolated PostgreSQL did not become ready')
        for number, dump in enumerate([existing, fresh]):
            if number:
                run('docker', 'exec', container, 'dropdb', '-U', 'restore_admin', 'restore_check')
                run('docker', 'exec', container, 'createdb', '-U', 'restore_admin', 'restore_check')
            with dump.open('rb') as source:
                toc = run('docker', 'exec', '-i', container, 'pg_restore', '--list', stdin=source)
            (backup/f'restore-{number}.toc').write_bytes(toc.stdout)
            with dump.open('rb') as source:
                restored = run('docker', 'exec', '-i', container, 'pg_restore',
                    '--exit-on-error', '--no-owner', '--no-privileges',
                    '-U', 'restore_admin', '-d', 'restore_check', stdin=source,
                    timeout=120, check=False)
            (backup/f'restore-{number}.stderr').write_bytes(restored.stderr)
            if restored.returncode:
                raise RuntimeError('Restore failed; inspect private log for '+str(dump))
            sql = """SELECT json_build_object(
                'public_tables', (SELECT count(*) FROM pg_tables WHERE schemaname='public'),
                'users', (SELECT count(*) FROM public.users),
                'documents', (SELECT count(*) FROM public.documents),
                'work_shifts', (SELECT count(*) FROM public.work_shifts),
                'migrations', (SELECT count(*) FROM public.migrations),
                'foreign_keys', (SELECT count(*) FROM pg_constraint WHERE contype='f'),
                'unvalidated_foreign_keys', (SELECT count(*) FROM pg_constraint
                                            WHERE contype='f' AND NOT convalidated));"""
            counts = json.loads(text('docker', 'exec', container, 'psql', '-X',
                '-v', 'ON_ERROR_STOP=1', '-U', 'restore_admin', '-d', 'restore_check', '-Atc', sql))
            assert counts['public_tables'] > 0 and counts['migrations'] > 0
            assert counts['unvalidated_foreign_keys'] == 0
            report['restores'].append({'source': str(dump), 'bytes': dump.stat().st_size,
                'sha256': hashlib.sha256(dump.read_bytes()).hexdigest(), 'checks': counts})
        report['result'] = 'PASS'
        (backup/'report.json').write_text(json.dumps(report, indent=2)+'\n')
        print('OLD_AND_FRESH_POSTGRES_RESTORES_PASSED', flush=True)
        print('PRIVATE_REPORT='+str(backup/'report.json'), flush=True)
        print('FRESH_DUMP_BYTES='+str(fresh.stat().st_size), flush=True)
    finally:
        if started:
            (backup/'isolated-postgres.log').write_bytes(
                run('docker', 'logs', container, check=False).stdout)
            cleaned = run('docker', 'rm', '-f', container, check=False)
            if cleaned.returncode and run('docker', 'inspect', container, check=False).returncode == 0:
                raise RuntimeError('Isolated container cleanup failed: '+container)
        (backup/'container-removed').write_text(container+'\n')


if __name__ == '__main__':
    main()
