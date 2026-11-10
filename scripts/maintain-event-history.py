#!/usr/bin/env python3
"""Root-owned production maintenance: encrypted export, checked Native, bounded pruning.
No credentials/closed keys in arguments. Only default.user_actions is pruned.
"""
import argparse
import base64
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import time
import urllib.request

BASE = Path('/var/lib/arm-event-history')


def run(*args, data=None):
    p = subprocess.run(args, input=data, capture_output=True, timeout=180)
    if p.returncode:
        raise RuntimeError('Local command failed: '+args[0])
    return p.stdout


def env_file(path):
    p = Path(path)
    s = p.stat()
    if s.st_uid != 0 or s.st_mode & 0o077:
        raise RuntimeError('Credential file must be root:0600')
    return dict(line.split('=', 1) for line in p.read_text().splitlines()
                if line and not line.startswith('#') and '=' in line)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--clear-reviewed-failures', action='store_true')
    parser.add_argument('--export-only', action='store_true')
    parser.add_argument('--prune-only', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0 or (args.export_only and args.prune_only):
        raise SystemExit('Root required; conflicting modes')
    os.umask(0o077)
    BASE.mkdir(mode=0o700, exist_ok=True)
    with (BASE/'maintenance.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        cfg = env_file('/etc/arm/services/clickhouse.env')
        info = json.loads(run('docker', 'inspect', 'arm-clickhouse'))[0]
        ips = [v['IPAddress'] for v in info['NetworkSettings']['Networks'].values() if v['IPAddress']]
        auth = base64.b64encode((cfg.get('CLICKHOUSE_USER', 'default')+':'+cfg.get('CLICKHOUSE_PASSWORD', '')).encode()).decode()
        def query(sql):
            request = urllib.request.Request('http://'+ips[0]+':8123/', data=sql.encode(), headers={'Authorization':'Basic '+auth})
            # No proxies or redirects for an internal credential-bearing request.
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, *a, **kw): return None
            with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=180) as r:
                return r.read()
        snapshot = int(query('SELECT toUnixTimestamp(now())').strip())
        cutoff = snapshot-72*3600
        predicate = f'event_time <= toDateTime({snapshot})'
        count = int(query('SELECT count() FROM default.user_actions WHERE '+predicate))
        old = int(query(f'SELECT count() FROM default.user_actions WHERE event_time < toDateTime({cutoff})'))
        if not args.apply:
            print(json.dumps({'mode':'inspect','snapshot_epoch':snapshot,'cutoff_epoch':cutoff,'rows':count,'eligible':old}))
            return
        if args.prune_only:
            query(f'ALTER TABLE default.user_actions DELETE WHERE event_time < toDateTime({cutoff}) SETTINGS mutations_sync=1')
            if int(query(f'SELECT count() FROM default.user_actions WHERE event_time < toDateTime({cutoff})')):
                raise RuntimeError('Pruning verification failed')
            deleted = 0
            if args.clear_reviewed_failures:
                php = b"<?php require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo Illuminate\\Support\\Facades\\DB::table('failed_jobs')->whereIn('id',range(1,21))->where('failed_at','<','2026-10-26')->delete();"
                deleted = int(run('docker','exec','-i','-u','www-data','arm-app','php','/dev/stdin',data=php))
            print(json.dumps({'mode':'prune-without-archive','cutoff_epoch':cutoff,'eligible_before':old,'reviewed_failures_deleted':deleted}))
            return
        recipient = Path('/etc/arm/backup-recipient').read_text().strip()
        if not recipient.startswith('age1') or '\n' in recipient:
            raise RuntimeError('Invalid age recipient')
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        target = BASE/f'events-{stamp}.tar.gz.age'
        if target.exists(): raise RuntimeError('Archive exists')
        with tempfile.TemporaryDirectory(prefix='.stage-', dir=BASE) as temp:
            work = Path(temp)
            schema = query('SHOW CREATE TABLE default.user_actions FORMAT TabSeparatedRaw')
            native = query('SELECT * FROM default.user_actions WHERE '+predicate+' FORMAT Native')
            (work/'user_actions.sql').write_bytes(schema+b';\n')
            (work/'user_actions.native').write_bytes(native)
            checked = run('docker','run','--rm','-i','--network=none','--read-only','--user=clickhouse',
                          '--cap-drop=ALL','--security-opt=no-new-privileges:true','--memory=768m','--cpus=0.5','--pids-limit=256',
                          '--tmpfs','/tmp:rw,nosuid,noexec,size=128m,mode=1777',
                          '--tmpfs','/var/lib/clickhouse:rw,nosuid,noexec,size=16m,mode=1777',
                          '--tmpfs','/var/log/clickhouse-server:rw,nosuid,noexec,size=16m,mode=1777',
                          '--entrypoint=clickhouse-local',info['Image'],'--path','/tmp/restore','--input-format','Native',
                          '--max_threads','2','--query','SELECT count() FROM table',data=native)
            if int(checked.strip()) != count: raise RuntimeError('Native count mismatch')
            php = b'''<?php
require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo json_encode(Illuminate\\Support\\Facades\\DB::table('failed_jobs')->whereIn('id',range(1,21))->where('failed_at','<','2026-10-26')->get());
'''
            failures = run('docker','exec','-i','-u','www-data','arm-app','php','/dev/stdin',data=php)
            json.loads(failures)
            (work/'failed_jobs.json').write_bytes(failures)
            manifest={'format':'arm-event-history-v1','snapshot_epoch':snapshot,'cutoff_epoch':cutoff,
                      'table':'default.user_actions','rows':count,'eligible_rows':old,'native_read_test':'PASS',
                      'files':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in work.iterdir()}}
            (work/'manifest.json').write_text(json.dumps(manifest,indent=2))
            archive=work/'export.tar.gz'
            with tarfile.open(archive,'w:gz') as tar:
                for p in sorted(work.iterdir()):
                    if p != archive: tar.add(p, arcname=p.name, recursive=False)
            partial=target.with_suffix(target.suffix+'.partial')
            run('age','-r',recipient,'-o',str(partial),data=archive.read_bytes())
            partial.rename(target)
        receipt={**manifest,'archive':target.name,'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'bytes':target.stat().st_size,'pruned':False}
        receipt_path=target.with_suffix(target.suffix+'.json')
        receipt_path.write_text(json.dumps(receipt,indent=2))
        if args.export_only:
            print(json.dumps({'mode':'export-only','archive':target.name,'rows_archived':count,'bytes':target.stat().st_size}))
            return
        # Recheck prior to deletion. Do not delete if the exported eligible set changed.
        if int(query(f'SELECT count() FROM default.user_actions WHERE event_time < toDateTime({cutoff})')) != old:
            raise RuntimeError('Eligible rows changed; archive retained, deletion skipped')
        query(f'ALTER TABLE default.user_actions DELETE WHERE event_time < toDateTime({cutoff}) SETTINGS mutations_sync=1')
        remaining_old=int(query(f'SELECT count() FROM default.user_actions WHERE event_time < toDateTime({cutoff})'))
        if remaining_old: raise RuntimeError('Pruning verification failed')
        if args.clear_reviewed_failures:
            deletion=b'''<?php
require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo Illuminate\\Support\\Facades\\DB::table('failed_jobs')->whereIn('id',range(1,21))->where('failed_at','<','2026-10-26')->delete();
'''
            receipt['reviewed_failures_deleted']=int(run('docker','exec','-i','-u','www-data','arm-app','php','/dev/stdin',data=deletion))
        receipt['pruned']=True
        receipt_path.write_text(json.dumps(receipt,indent=2))
        print(json.dumps({'archive':target.name,'rows_archived':count,'rows_pruned':old,'reviewed_failures_deleted':receipt.get('reviewed_failures_deleted',0)}))


if __name__ == '__main__':
    main()
