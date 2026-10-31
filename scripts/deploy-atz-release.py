#!/usr/bin/env python3
"""Reviewed ATZ 2026-10-31 release, manual root deployment with scoped rollback.

Only two additive migrations; preserves unrelated host changes and all DB data.
Not an unattended updater or general-purpose arbitrary migration runner.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import traceback

ROOT = Path('/home/deploy/arm')
MIGRATIONS = ['database/migrations/2026_10_31_100000_create_instructor_training_requests.php',
              'database/migrations/2026_10_31_110000_create_instructor_training_completions.php']
APK_SHA = 'b9bd959f134a0229c081293a00baaea061347959ec8d5952612e32d12bb0be98'
CERT = '2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f'


def run(*args, timeout=120, **kwargs):
    return subprocess.run(args, capture_output=True, check=True, timeout=timeout, **kwargs)


def git(*args):
    return run('git', '--no-optional-locks', '-c', 'safe.directory='+str(ROOT), '-C', str(ROOT), *args).stdout


def sha(path):
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024*1024), b''):
            h.update(chunk)
    return h.hexdigest()


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--apply', action='store_true')
    p.add_argument('--commit', required=True)
    p.add_argument('--artifacts', type=Path, required=True)
    p.add_argument('--artifact-sha256', required=True)
    a = p.parse_args()
    assert a.apply and os.geteuid() == 0 and re.fullmatch('[0-9a-f]{40}', a.commit)
    os.chdir(ROOT)
    os.umask(0o077)
    os.environ.pop('COMPOSE_FILE', None)
    os.environ['COMPOSE_ANSI'] = 'never'
    before = git('rev-parse', 'HEAD').decode().strip()
    assert before != a.commit
    git('merge-base', '--is-ancestor', before, a.commit)
    changed = git('diff', '--name-only', before, a.commit).decode().splitlines()
    dirty = set(git('diff', '--name-only', 'HEAD').decode().splitlines())
    assert not dirty.intersection(changed), 'Target source paths have host modifications'
    assert sha(a.artifacts) == a.artifact_sha256
    backup_unit = run('systemctl', 'show', 'arm-pre-atz-backup-20261031',
                      '-p', 'Result', '-p', 'ExecMainStatus').stdout.decode()
    assert 'Result=success' in backup_unit and 'ExecMainStatus=0' in backup_unit
    receipts = sorted(Path('/var/lib/arm-backups').glob('arm-full-*.age.json'))
    assert receipts and datetime.now().timestamp()-receipts[-1].stat().st_mtime < 7200
    receipt = json.loads(receipts[-1].read_text())
    assert receipt['postgres_restore_test'] == 'PASS'
    assert sha(receipts[-1].with_suffix('')) == receipt['sha256']
    config = json.loads(run('docker', 'compose', 'config', '--format', 'json').stdout)
    app = config['services']['app']
    assert app['read_only'] and next(v for v in app['volumes'] if v['target'] == '/var/www')['read_only']
    assert app['environment']['DB_USERNAME'] == 'arm_runtime'
    assert app['entrypoint'] == ['/bin/sh', '/var/www/docker/readonly-entrypoint.sh']
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/root')/('arm-atz-release-'+stamp)
    backup.mkdir(mode=0o700)
    (backup/'before-commit').write_text(before+'\n')
    (backup/'target-commit').write_text(a.commit+'\n')
    (backup/'source-before.tar').write_bytes(git('archive', '--format=tar', before))
    shutil.copytree(ROOT/'public/build', backup/'build-before')
    shutil.copy2(ROOT/'public/downloads/tch15-android.apk', backup/'android-before.apk')
    (backup/'host-status-before').write_bytes(git('status', '--short'))
    incoming = backup/'incoming'
    incoming.mkdir()
    with tarfile.open(a.artifacts, 'r:gz') as archive:
        total = 0
        for item in archive:
            name = PurePosixPath(item.name)
            assert not name.is_absolute() and '..' not in name.parts
            assert name.parts and name.parts[0] in ['build', 'downloads']
            assert item.isdir() or item.isfile()
            total += item.size
            assert total < 200*1024**2
            target = incoming/name
            if item.isdir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with target.open('xb') as f:
                    shutil.copyfileobj(archive.extractfile(item), f)
    installer = incoming/'downloads/tch15-android.apk'
    metadata = incoming/('downloads/release-'+APK_SHA+'.json')
    assert sha(installer) == APK_SHA
    release = json.loads(metadata.read_text())
    assert release['sha256'] == APK_SHA and release['certificate_sha256'] == CERT
    assert release['version_code'] == 15 and release['version'] == '1.0.14'
    assert release['package'] == 'ru.tch15.arm'
    old_sha = sha(backup/'android-before.apk')
    old_release = json.loads((ROOT/('public/downloads/release-'+old_sha+'.json')).read_text())
    assert old_release['certificate_sha256'] == CERT and old_release['version_code'] < 15
    def logged(command, label, timeout=180):
        result = subprocess.run(command, capture_output=True, timeout=timeout)
        (backup/(label+'.log')).write_bytes(result.stdout+result.stderr)
        if result.returncode:
            raise RuntimeError('Release command failed: '+label)
    def restart(label):
        logged(['docker','compose','up','-d','--no-deps','--force-recreate','--wait',
                '--wait-timeout','60','app'], label+'-app')
        logged(['docker','exec','arm-nginx','nginx','-t'], label+'-nginx-test')
        logged(['docker','exec','arm-nginx','nginx','-s','reload'], label+'-nginx-reload')
    switched = False
    try:
        for index, path in enumerate(MIGRATIONS):
            target = ROOT/path
            assert not target.exists(), 'Migration already present: inspect before release'
            target.write_bytes(git('show', a.commit+':'+path))
            target.chmod(0o644)
            logged(['python3','scripts/arm-admin-artisan.py','migrate','--force','--path='+path],
                   'migration-'+str(index))
        # The live application is still old; only these two additive migrations ran.
        tables = ['instructor_training_requests','instructor_training_members',
                  'instructor_training_operations','instructor_training_completions',
                  'instructor_training_attendance']
        php = """require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';
$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach(json_decode($argv[1],true) as $table){
 foreach(['SELECT','INSERT','UPDATE','DELETE'] as $priv){
  $r=Illuminate\\Support\\Facades\\DB::selectOne('SELECT has_table_privilege(current_user,?,?) AS allowed',['public.'.$table,$priv]);
  if(!$r->allowed)exit(2);
 }
 Illuminate\\Support\\Facades\\DB::table($table)->count();
}echo 'RUNTIME_ATZ_DML_OK';"""
        logged(['docker','exec','arm-app','php','-r',php,json.dumps(tables)],'runtime-new-tables')
        for path in MIGRATIONS:
            assert (ROOT/path).read_bytes() == git('show',a.commit+':'+path)
            (ROOT/path).unlink()
        logged(['runuser','-u','deploy','--','git','-C',str(ROOT),'merge','--ff-only',a.commit], 'code-switch')
        switched = True
        # Keep old hashed assets for open pages; replace manifest only after new files exist.
        for source in (incoming/'build').rglob('*'):
            if source.is_file() and source.name != 'manifest.json':
                target = ROOT/'public/build'/source.relative_to(incoming/'build')
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source,target)
                target.chmod(0o644)
        manifest = ROOT/'public/build/manifest.json'
        shutil.copy2(incoming/'build/manifest.json',manifest.with_suffix('.json.next'))
        manifest.with_suffix('.json.next').chmod(0o644)
        os.replace(manifest.with_suffix('.json.next'),manifest)
        restart('release')
        logged(['python3','scripts/verify-readonly-runtime.py','--container','arm-app',
                '--url','https://www.xn---15-7edz.xn--p1ai'],'runtime-smoke')
        for path in ['/api/mobile/journal/training-requests?month=2026-10',
                     '/api/mobile/journal/training-requests/crew']:
            status=run('curl','-sS','--max-time','15','-o','/dev/null','-w','%{http_code}',
                       '-H','Accept: application/json','https://www.xn---15-7edz.xn--p1ai'+path).stdout
            assert status == b'401'
        destination = ROOT/'public/downloads/tch15-android.apk'
        shutil.copy2(installer,destination.with_suffix('.apk.next'))
        destination.with_suffix('.apk.next').chmod(0o644)
        assert sha(destination.with_suffix('.apk.next')) == APK_SHA
        shutil.copy2(metadata,ROOT/'public/downloads'/metadata.name)
        (ROOT/'public/downloads'/metadata.name).chmod(0o644)
        os.replace(destination.with_suffix('.apk.next'),destination)
        assert sha(destination) == APK_SHA
        (backup/'success').write_text('Code/assets/API/APK checks passed. New tables retained.\n')
        print('ATZ_WEB_AND_APK_DEPLOYED',flush=True)
        print('COMMIT='+a.commit,flush=True)
        print('BACKUP='+str(backup),flush=True)
    except BaseException:
        (backup/'failure.log').write_text(traceback.format_exc())
        if switched:
            # Restore only touched source files; never reset/clean unrelated host assets.
            logged(['runuser','-u','deploy','--','git','-C',str(ROOT),
                    'restore','--source='+before,'--worktree','--',*changed],'rollback-source')
            shutil.copytree(backup/'build-before',ROOT/'public/build',dirs_exist_ok=True)
            shutil.copy2(backup/'android-before.apk',ROOT/'public/downloads/tch15-android.apk')
            restart('rollback')
        (backup/'rolled-back').write_text('Source/assets restored if switched; migrations retained. Inspect Git worktree before retry.\n')
        raise SystemExit('Release failed; inspect private logs in '+str(backup))


if __name__ == '__main__':
    main()
