#!/usr/bin/env python3
"""Build a private, age-encrypted ARM recovery set without pausing production.

Root-only/manual first stage. No retention deletion, schedule, remote key access,
schema changes or application writes. Object/storage inventories must stay stable.
Does not claim a distributed transaction or a complete operating-system image.
"""
import argparse
import base64
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile
import traceback
import urllib.request


REPO = Path('/home/deploy/arm')
MAX_BYTES = 4 * 1024**3


def run(*args, timeout=60, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, timeout=timeout, **kwargs)


def text(*args):
    return run(*args).stdout.decode().strip()


def git_args(*args):
    # Per-invocation trust for this audited deploy-owned checkout; no global Git change.
    return ['git', '-c', 'safe.directory='+str(REPO), '-C', str(REPO), *args]


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024*1024), b''):
            h.update(chunk)
    return h.hexdigest()


def save_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2,
                               default=str)+'\n')


def env_file(path):
    info = path.stat()
    assert info.st_uid == 0 and not info.st_mode & 0o077
    return dict(line.split('=', 1) for line in path.read_text().splitlines()
                if line and not line.startswith('#') and '=' in line)


def inspect(name):
    return json.loads(run('docker', 'inspect', name).stdout)[0]


def ip_of(container):
    return next(n['IPAddress'] for n in container['NetworkSettings']['Networks'].values()
                if n['IPAddress'])


def tree_inventory(root):
    """Refuse symlinks/special files; never follow a user-created link as root."""
    assert root.is_dir() and not root.is_symlink()
    result = {}
    for parent, dirs, files in os.walk(root, followlinks=False):
        for name in dirs:
            assert not (Path(parent)/name).is_symlink(), 'Storage directory symlink requires review'
        for name in files:
            p = Path(parent)/name
            assert p.is_file() and not p.is_symlink(), 'Storage special file requires review'
            info = p.stat()
            result[p.relative_to(root).as_posix()] = {'bytes': info.st_size, 'sha256': digest(p)}
    assert sum(item['bytes'] for item in result.values()) < MAX_BYTES
    return result


def s3_inventory(client, bucket):
    result = []
    for page in client.get_paginator('list_objects_v2').paginate(Bucket=bucket):
        for item in page.get('Contents', []):
            head = client.head_object(Bucket=bucket, Key=item['Key'])
            tags = client.get_object_tagging(Bucket=bucket, Key=item['Key'])['TagSet']
            metadata = {k: head[k] for k in [
                'ContentType', 'ContentEncoding', 'ContentLanguage', 'ContentDisposition',
                'CacheControl', 'Expires', 'Metadata', 'ServerSideEncryption', 'VersionId'] if k in head}
            result.append({'key': item['Key'], 'bytes': item['Size'], 'etag': item['ETag'],
                           'modified': item['LastModified'].isoformat(), 'metadata': metadata,
                           'tags': sorted(tags, key=lambda t: t['Key'])})
    return sorted(result, key=lambda item: item['key'])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run', action='store_true')
    parser.add_argument('--recipient-file', type=Path, required=True)
    parser.add_argument('--output-dir', type=Path, default=Path('/var/lib/arm-backups'))
    args = parser.parse_args()
    if not args.run or os.geteuid() != 0:
        raise SystemExit('Requires root and explicit --run. This is not a production restore tool.')
    os.umask(0o077)
    recipient = args.recipient_file.read_text().strip()
    assert re.fullmatch(r'age1[0-9a-z]+', recipient)
    base = args.output_dir
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    assert not base.is_symlink() and base.stat().st_uid == 0
    assert base.stat().st_mode & 0o777 == 0o700
    assert shutil.disk_usage(base).free > MAX_BYTES*2, 'Require 8 GiB staging headroom'
    lock = (base/'build.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    final = base/('arm-full-'+stamp+'.tar.gz.age')
    assert not final.exists()
    partial = final.with_suffix(final.suffix+'.partial')
    started = datetime.now(timezone.utc).isoformat()
    work = Path(tempfile.mkdtemp(prefix='work-', dir=base))
    try:
        import boto3
        from botocore.config import Config
        from botocore.exceptions import ClientError

        containers = {name: inspect(name) for name in [
            'arm-app', 'arm-postgres', 'arm-minio', 'arm-clickhouse', 'arm-nginx',
            'arm-redis', 'arm-meilisearch', 'arm-clamav-1']}
        minio = env_file(Path('/etc/arm/services/minio.env'))
        s3 = boto3.client('s3', endpoint_url='http://'+ip_of(containers['arm-minio'])+':9000',
            aws_access_key_id=minio['MINIO_ROOT_USER'],
            aws_secret_access_key=minio['MINIO_ROOT_PASSWORD'], region_name='us-east-1',
            config=Config(signature_version='s3v4', connect_timeout=5, read_timeout=30,
                          retries={'max_attempts': 2}, s3={'addressing_style': 'path'}))
        buckets = sorted(b['Name'] for b in s3.list_buckets()['Buckets'])
        assert buckets, 'No object buckets found'
        inventories = {}
        bucket_configs = {}
        for bucket in buckets:
            versioning = s3.get_bucket_versioning(Bucket=bucket)
            assert not versioning.get('Status'), 'Versioned bucket needs version-aware exporter'
            config = {'versioning': versioning, 'acl': s3.get_bucket_acl(Bucket=bucket)}
            for action, key, missing in [
                ('get_bucket_policy', 'policy', 'NoSuchBucketPolicy'),
                ('get_bucket_lifecycle_configuration', 'lifecycle', 'NoSuchLifecycleConfiguration'),
                ('get_bucket_cors', 'cors', 'NoSuchCORSConfiguration'),
                ('get_bucket_tagging', 'tags', 'NoSuchTagSet'),
                ('get_object_lock_configuration', 'object_lock', 'ObjectLockConfigurationNotFoundError')]:
                try:
                    config[key] = getattr(s3, action)(Bucket=bucket)
                except ClientError as error:
                    if error.response['Error']['Code'] != missing:
                        raise
            config['notification'] = s3.get_bucket_notification_configuration(Bucket=bucket)
            bucket_configs[bucket] = config
            inventories[bucket] = s3_inventory(s3, bucket)
        assert sum(item['bytes'] for inv in inventories.values() for item in inv) < MAX_BYTES
        mount = next(m for m in containers['arm-app']['Mounts'] if m['Destination'] == '/var/www/storage')
        assert mount['Type'] == 'volume'
        storage = Path(mount['Source'])/'app'
        storage_before = tree_inventory(storage)
        commit = text(*git_args('rev-parse', 'HEAD'))

        for filename, command in [
            ('postgres.dump', 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" '
                              '--format=custom --compress=6 --lock-wait-timeout=5s'),
            ('postgres-roles.sql', 'exec pg_dumpall -U "$POSTGRES_USER" --roles-only')]:
            with (work/filename).open('wb') as output:
                subprocess.run(['docker', 'exec', 'arm-postgres', 'sh', '-c', command],
                               stdout=output, stderr=subprocess.PIPE, check=True, timeout=120)

        object_dir = work/'objects'
        object_dir.mkdir()
        object_manifest = []
        for bucket, inv in inventories.items():
            for item in inv:
                # Never use an object key as a filesystem path.
                name = hashlib.sha256((bucket+'\0'+item['key']).encode()).hexdigest()+'.bin'
                destination = object_dir/name
                response = s3.get_object(Bucket=bucket, Key=item['key'], IfMatch=item['etag'])
                try:
                    with destination.open('xb') as output:
                        copied = 0
                        while chunk := response['Body'].read(1024*1024):
                            copied += len(chunk)
                            assert copied <= item['bytes']
                            output.write(chunk)
                    assert copied == item['bytes']
                finally:
                    response['Body'].close()
                object_manifest.append(dict(item, bucket=bucket, file='objects/'+name,
                                            sha256=digest(destination)))
        save_json(work/'objects.json', {'buckets': bucket_configs, 'objects': object_manifest})
        (work/'storage').mkdir()
        for relative in storage_before:
            destination = work/'storage'/relative
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            shutil.copyfile(storage/relative, destination, follow_symlinks=False)
            assert destination.is_file() and not destination.is_symlink()
            assert digest(destination) == storage_before[relative]['sha256']
        assert tree_inventory(storage) == storage_before, 'Storage changed; reject and retry backup'
        for bucket in buckets:
            assert s3_inventory(s3, bucket) == inventories[bucket], \
                'Object inventory changed; reject and retry backup'

        # Application analytics only. No raw ClickHouse volumes or system logs.
        ch = env_file(Path('/etc/arm/services/clickhouse.env'))
        authorization = base64.b64encode((ch.get('CLICKHOUSE_USER', 'default')+':'+
                                         ch.get('CLICKHOUSE_PASSWORD', '')).encode()).decode()
        def query(sql, target=None):
            request = urllib.request.Request('http://'+ip_of(containers['arm-clickhouse'])+':8123/',
                data=sql.encode(), headers={'Authorization': 'Basic '+authorization})
            with urllib.request.urlopen(request, timeout=60) as response:
                if target:
                    with target.open('wb') as output:
                        shutil.copyfileobj(response, output)
                    return None
                return response.read()
        tables = json.loads(query("SELECT database,name,engine FROM system.tables WHERE database NOT IN "
            "('system','information_schema','INFORMATION_SCHEMA') ORDER BY database,name FORMAT JSON"))['data']
        (work/'clickhouse').mkdir()
        for table in tables:
            assert re.fullmatch(r'[A-Za-z0-9_]+', table['database'])
            assert re.fullmatch(r'[A-Za-z0-9_]+', table['name'])
            assert table['engine'].endswith('MergeTree'), 'Unsupported analytics engine needs review'
            name = table['database']+'.'+table['name']
            sql_name = '`'+table['database']+'`.`'+table['name']+'`'
            schema = query('SHOW CREATE TABLE '+sql_name+' FORMAT TabSeparatedRaw').decode().strip()
            (work/'clickhouse'/(name+'.sql')).write_text(schema+';\n')
            query('SELECT * FROM '+sql_name+' FORMAT Native', work/'clickhouse'/(name+'.native'))
            assert query('SHOW CREATE TABLE '+sql_name+' FORMAT TabSeparatedRaw').decode().strip() == schema
            candidate = 'arm-analytics-backup-check-'+stamp.lower()+'-'+str(tables.index(table))
            try:
                with (work/'clickhouse'/(name+'.native')).open('rb') as source:
                    checked = run('docker', 'run', '--rm', '-i', '--name', candidate,
                        '--network=none', '--read-only', '--user=clickhouse',
                        '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
                        '--memory=768m', '--cpus=0.5', '--pids-limit=256',
                        '--tmpfs', '/tmp:rw,nosuid,noexec,size=128m,mode=1777',
                        '--tmpfs', '/var/lib/clickhouse:rw,nosuid,noexec,size=16m,mode=1777',
                        '--tmpfs', '/var/log/clickhouse-server:rw,nosuid,noexec,size=16m,mode=1777',
                        '--entrypoint=clickhouse-local', containers['arm-clickhouse']['Image'],
                        '--path', '/tmp/restore', '--input-format', 'Native', '--max_threads', '2',
                        '--query', 'SELECT count() FROM table', stdin=source, timeout=90)
                table['native_verified_rows'] = int(checked.stdout.strip())
            finally:
                subprocess.run(['docker', 'rm', '-f', candidate], capture_output=True, timeout=30)
        save_json(work/'clickhouse'/'tables.json', tables)

        # Private configuration includes recovery secrets; only age ciphertext is exported.
        sources = [Path('/etc/arm'), REPO/'.env', REPO/'ssl', Path('/etc/ssh'),
                   Path('/etc/fail2ban'), Path('/etc/bind'), Path('/etc/proftpd'),
                   Path('/etc/systemd/system'), Path('/etc/cron.d'),
                   Path('/var/spool/cron/crontabs/root'), Path('/etc/letsencrypt')]
        sources += sorted(REPO.glob('docker-compose*.yml'))+sorted(REPO.glob('docker-compose*.yaml'))
        with tarfile.open(work/'configuration.tar', 'w') as archive:
            for source in sources:
                if source.exists():
                    archive.add(source, arcname=str(source).lstrip('/'))
        with (work/'source.tar').open('wb') as output:
            subprocess.run(git_args('archive', '--format=tar', 'HEAD'),
                           stdout=output, stderr=subprocess.PIPE, check=True, timeout=90)
        with tarfile.open(work/'public.tar', 'w') as archive:
            archive.add(REPO/'public', arcname='public')
        (work/'tracked-changes.patch').write_bytes(run(*git_args('diff', 'HEAD')).stdout)
        save_json(work/'containers.json', containers)
        for tool in ['iptables-save', 'ip6tables-save']:
            (work/(tool+'.txt')).write_bytes(run(tool).stdout)
        save_json(work/'packages.json', {'age': text('age', '--version'),
            'packages': text('dpkg-query', '-W', '-f=${Package} ${Version}\\n')})
        assert text(*git_args('rev-parse', 'HEAD')) == commit

        # Reuse the already reviewed isolated restore drill for this exact dump.
        drill = run('python3', str(Path(__file__).with_name('verify-postgres-backup.py')),
                    '--run', '--existing-dump', str(work/'postgres.dump'), timeout=420)
        report_path = next(line.split('=', 1)[1] for line in drill.stdout.decode().splitlines()
                           if line.startswith('PRIVATE_REPORT='))
        report = json.loads(Path(report_path).read_text())
        assert report['result'] == 'PASS'
        assert report['restores'][0]['sha256'] == digest(work/'postgres.dump')
        save_json(work/'postgres-restore-report.json', report)
        files = tree_inventory(work)
        assert sum(f['bytes'] for f in files.values()) < MAX_BYTES
        manifest = {'format': 'arm-recovery-set-v1', 'started_utc': started,
            'completed_utc': datetime.now(timezone.utc).isoformat(), 'commit': commit,
            'consistency': 'objects/storage inventories stable across dump/copy; not a distributed transaction',
            'postgres_restore_test': 'PASS', 'buckets': len(buckets), 'objects': len(object_manifest),
            'analytics_tables': tables, 'storage_files': len(storage_before), 'files': files,
            'exclusions': ['Redis sessions/cache', 'Meilisearch rebuildable index',
                           'ClickHouse system logs', 'OCI image binaries', 'complete OS disk image'],
            'restore_requires': ['reviewed role/grant restoration', 'registry access for recorded images',
                                 'MinIO import from objects.json', 'isolated complete application rehearsal']}
        save_json(work/'manifest.json', manifest)
        proc = subprocess.Popen(['age', '-r', recipient, '-o', str(partial)],
                                stdin=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            with tarfile.open(fileobj=proc.stdin, mode='w|gz') as archive:
                archive.add(work, arcname='arm-backup')
            proc.stdin.close()
            error = proc.stderr.read()
            if proc.wait(timeout=60):
                raise RuntimeError('age encryption failed')
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            proc.stderr.close()
        assert partial.stat().st_size > 0
        partial.rename(final)
        receipt = {'format': 'arm-recovery-set-v1+tar.gz+age', 'archive': final.name,
                   'bytes': final.stat().st_size, 'sha256': digest(final), 'commit': commit,
                   'started_utc': started, 'completed_utc': datetime.now(timezone.utc).isoformat(),
                   'postgres_restore_test': 'PASS', 'objects': len(object_manifest),
                   'storage_files': len(storage_before), 'analytics_tables': len(tables)}
        save_json(final.with_suffix(final.suffix+'.json'), receipt)
        print('ENCRYPTED_ARM_RECOVERY_SET_READY', flush=True)
        print('ARCHIVE='+str(final), flush=True)
        print('RECEIPT='+str(final.with_suffix(final.suffix+'.json')), flush=True)
        print('SHA256='+receipt['sha256'], flush=True)
    except BaseException:
        (base/('failure-'+stamp+'.log')).write_text(traceback.format_exc())
        partial.unlink(missing_ok=True)
        raise SystemExit('Backup failed; inspect private failure log. No incomplete archive accepted.')
    finally:
        shutil.rmtree(work)
        lock.close()


if __name__ == '__main__':
    main()
