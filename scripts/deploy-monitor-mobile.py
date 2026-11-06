#!/usr/bin/env python3
"""Manual scoped monitoring release; no migrations, no database/service recreation.

Use the production-based release branch, not main with unrelated pending work.
Requires reviewed files, a fresh verified encrypted backup and two APK hashes.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import traceback

ROOT = Path('/home/deploy/arm')
BASE = '6954539498c5c79b1e2df43ddf56be9e4b82399a'
OVERLAY = 'docker-compose.monitor-mobile.yml'


def run(*args, timeout=120, **kwargs):
    return subprocess.run(args, capture_output=True, check=True, timeout=timeout, **kwargs)


def git(*args):
    return run('git', '-c', 'safe.directory='+str(ROOT), '-C', str(ROOT), *args).stdout


def digest(path):
    with Path(path).open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def copy_atomic(source, destination, mode, owner=None):
    temp = destination.with_name(destination.name+'.monitor-next')
    shutil.copyfile(source, temp)
    temp.chmod(mode)
    if owner: os.chown(temp, *owner)
    os.replace(temp, destination)


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--commit', required=True)
    p.add_argument('--main-apk', type=Path, required=True)
    p.add_argument('--monitor-apk', type=Path, required=True)
    p.add_argument('--main-sha', required=True)
    p.add_argument('--monitor-sha', required=True)
    p.add_argument('--apply', action='store_true')
    a = p.parse_args()
    assert os.geteuid() == 0 and a.apply and re.fullmatch('[a-f0-9]{40}', a.commit)
    assert digest(a.main_apk) == a.main_sha and digest(a.monitor_apk) == a.monitor_sha
    os.chdir(ROOT)
    os.environ.pop('COMPOSE_FILE', None)
    assert git('rev-parse', 'HEAD').decode().strip() == BASE, 'Production changed; inspect again'
    git('merge-base', '--is-ancestor', BASE, a.commit)
    changed = git('diff', '--name-only', BASE, a.commit).decode().splitlines()
    assert changed and all(x.startswith(('app/Http/Controllers/Api/Monitor', 'app/Http/Middleware/EnsureMonitor',
        'app/Http/Middleware/RejectMonitor', 'app/Services/Monitor', 'config/monitor', 'ops/monitoring/',
        'tests/', 'MONITOR', 'deployments/monitor-', 'scripts/deploy-monitor-mobile'))
        or x in ['AGENTS.md', 'routes/api.php', OVERLAY] for x in changed)
    dirty = set(git('diff', 'HEAD', '--name-only').decode().splitlines())
    assert not dirty.intersection(changed), 'Modified production source overlaps release'
    receipts = sorted(Path('/var/lib/arm-backups').glob('arm-full-*.age.json'))
    receipt = receipts[-1]
    assert datetime.now().timestamp()-receipt.stat().st_mtime < 7200
    assert json.loads(receipt.read_text())['postgres_restore_test'] == 'PASS'
    assert receipt.with_suffix('').is_file()
    assert shutil.disk_usage(ROOT).free > 4*1024**3
    installer = Path('/var/lib/arm-monitoring/installers/tch15-monitor.apk')
    main_apk = ROOT/'public/downloads/tch15-android.apk'
    backup = Path('/root')/('arm-monitor-release-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
    backup.mkdir(mode=0o700)
    shutil.copy2(ROOT/'.env', backup/'env-before')
    shutil.copy2(main_apk, backup/'main-before.apk')
    if installer.exists(): shutil.copy2(installer, backup/'monitor-before.apk')
    existing = set(git('ls-tree','-r','--name-only',BASE).decode().splitlines())
    (backup/'sources-before.tar').write_bytes(git('archive','--format=tar',BASE,*[x for x in changed if x in existing]))
    old_env = (ROOT/'.env').read_text()
    matches = list(re.finditer(r'^COMPOSE_FILE=(.+)$', old_env, re.M))
    assert len(matches)==1
    value=matches[0][1].strip().strip('\"\'')
    assert 'security' in value and 'readonly' in value and OVERLAY not in value
    new_env=old_env[:matches[0].start()]+"COMPOSE_FILE="+value+':'+OVERLAY+old_env[matches[0].end():]
    switched=False
    def compose():
        result=run('docker','compose','config','--quiet')
        result=run('docker','compose','up','-d','--no-deps','--force-recreate','--wait','--wait-timeout','90','app',timeout=180)
    try:
        # Preserve caller's staged vendor assets and Nginx changes: only fast-forward.
        os.umask(0o022)
        git('merge','--ff-only',a.commit);switched=True
        (ROOT/'.env').write_text(new_env)
        installer.parent.mkdir(mode=0o755, exist_ok=True)
        os.chown(installer.parent, 0, 0)
        installer.parent.chmod(0o755)
        copy_atomic(a.monitor_apk, installer,0o444,(0,0))
        compose()
        result=run('python3','scripts/verify-readonly-runtime.py','--container','arm-app','--url','https://www.xn---15-7edz.xn--p1ai',timeout=120)
        (backup/'verify.log').write_bytes(result.stdout+result.stderr)
        run('docker','exec','-u','www-data','arm-app','test','-r','/run/arm-metrics/arm.prom')
        run('docker','exec','arm-nginx','nginx','-t')
        # Check authenticated routes remain closed before publishing the main APK.
        for endpoint in ['snapshot','installer']:
            status=run('curl','--silent','--max-time','10','-o','/dev/null','-w','%{http_code}',
                '-H','Accept: application/json','https://www.xn---15-7edz.xn--p1ai/api/monitor/'+endpoint).stdout
            assert status==b'401', 'Unexpected unauthenticated monitoring response'
        copy_atomic(a.main_apk, main_apk,0o644)
        assert digest(main_apk)==a.main_sha and digest(installer)==a.monitor_sha
        (backup/'success').write_text(a.commit+'\n')
        print('MONITOR_RELEASE_OK '+a.commit+' BACKUP='+str(backup))
    except Exception as failure:
        (backup/'failure.log').write_text(traceback.format_exc())
        if isinstance(failure, subprocess.CalledProcessError):
            (backup/'command-failure.log').write_bytes((failure.stdout or b'') + (failure.stderr or b''))
        if switched:
            (ROOT/'.env').write_text(old_env)
            git('restore','--source='+BASE,'--staged','--worktree','--',*changed)
            if (backup/'monitor-before.apk').exists():copy_atomic(backup/'monitor-before.apk',installer,0o444,(0,0))
            elif installer.exists():installer.unlink()
            copy_atomic(backup/'main-before.apk',main_apk,0o644)
            compose()
            (backup/'rollback').write_text('Sources and APK restored; HEAD still identifies attempted release. Inspect before next deployment.\n')
        raise SystemExit('Release failed; restored scoped files. Inspect '+str(backup))


if __name__=='__main__':main()
