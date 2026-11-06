#!/usr/bin/env python3
"""Manual, first-install-only root deployment. Leaves application containers and DB intact."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import time
import urllib.request

ROOT=Path('/home/deploy/arm')
CONFIG=Path('/etc/arm-monitoring')
DATA=Path('/var/lib/arm-monitoring')
LIB=Path('/usr/local/libexec/arm-monitoring')
OLD_NGINX_SHA='e11668c6ff162d4958ecfd89d949c02c06f6d6730c6b52222009a1b6438eb510'
MARKER="# Aggregate monitoring stream: no IP, URI, query string, user or token.\nlog_format arm_metrics 'arm_metrics $msec $status $request_time';\naccess_log /dev/stdout arm_metrics;\n\n"
IMAGES={'grafana':'grafana/grafana:13.3.0','prometheus':'prom/prometheus:v3.16.0'}

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');parser.add_argument('--source',type=Path,required=True);a=parser.parse_args()
    assert os.geteuid()==0 and a.apply
    os.umask(0o077)
    assert not CONFIG.exists(), 'First install only; inspect existing monitoring before updating'
    for port in [3000,9090,9108]:
        with socket.socket() as s:
            try:s.bind(('127.0.0.1',port))
            except OSError:raise SystemExit('Required loopback port is occupied')
    assert shutil.disk_usage('/').free>5*1024**3
    conf=ROOT/'docker/nginx/default.conf';old=conf.read_bytes()
    original_plain=hashlib.sha256(old).hexdigest()==OLD_NGINX_SHA
    already_marked=old.startswith(MARKER.encode()) and hashlib.sha256(old[len(MARKER.encode()):]).hexdigest()==OLD_NGINX_SHA
    assert original_plain or already_marked, 'Unexpected nginx config: review manually'
    backup=Path('/root')/('arm-monitoring-install-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
    backup.mkdir(mode=0o700);(backup/'nginx-before.conf').write_bytes(old)
    def run(args,label,timeout=90):
        result=subprocess.run(args,capture_output=True,timeout=timeout)
        file=backup/(label+'.log');file.write_bytes(result.stdout+result.stderr);file.chmod(0o600)
        if result.returncode:raise RuntimeError('Step failed: '+label+' (see private log)')
        return result.stdout
    # Capture only limited metadata. Never archive or print Docker inspect Config/Env.
    before=json.loads(run(['docker','inspect','--format','{{json .State}}','arm-app'],'app-state-before'))
    run(['docker','exec','arm-nginx','nginx','-t'],'nginx-before')
    images={}
    for name,tag in IMAGES.items():
        run(['docker','pull',tag],'pull-'+name,timeout=300)
        images[name]=json.loads(run(['docker','image','inspect','--format','{{json .RepoDigests}}',tag],'digest-'+name))[0]
    # Pulling images is reversible and does not stop any production container.
    for path in [CONFIG,CONFIG/'grafana',CONFIG/'grafana/datasources',CONFIG/'grafana/dashboards',CONFIG/'dashboards',LIB,DATA,DATA/'metrics']:
        path.mkdir(parents=True,exist_ok=True,mode=0o755);path.chmod(0o755)
    LIB.parent.chmod(0o755)  # Parent may have been created under private umask.
    (DATA/'private').mkdir(mode=0o700)
    for name,uid in [('grafana',472),('prometheus',65534)]:
        p=DATA/name;p.mkdir(mode=0o700);os.chown(p,uid,uid)
    for name in ['collector.py','compose.yml','prometheus.yml','rules.yml']:
        dest=LIB/name if name=='collector.py' else CONFIG/name
        shutil.copyfile(a.source/name,dest);dest.chmod(0o644)
    shutil.copyfile(a.source/'dashboard.json',CONFIG/'dashboards/arm.json')
    (CONFIG/'dashboards/arm.json').chmod(0o644)
    (CONFIG/'images.env').write_text('GRAFANA_IMAGE='+images['grafana']+'\nPROMETHEUS_IMAGE='+images['prometheus']+'\n');(CONFIG/'images.env').chmod(0o644)
    for name in ['grafana-admin-password','grafana-secret-key']:
        path=CONFIG/name;path.write_text(secrets.token_urlsafe(36)+'\n');os.chown(path,0,472);path.chmod(0o440)
    (CONFIG/'grafana/datasources/prometheus.yml').write_text('apiVersion: 1\ndatasources:\n  - name: ARM Prometheus\n    uid: arm-prometheus\n    type: prometheus\n    access: proxy\n    url: http://127.0.0.1:9090\n    isDefault: true\n    editable: false\n')
    (CONFIG/'grafana/dashboards/arm.yml').write_text('apiVersion: 1\nproviders:\n  - name: ARM\n    orgId: 1\n    folder: ARM\n    type: file\n    disableDeletion: true\n    editable: false\n    updateIntervalSeconds: 30\n    options:\n      path: /var/lib/grafana/dashboards\n')
    for path in (CONFIG/'grafana').rglob('*.yml'):path.chmod(0o644)
    php=r'''require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo json_encode(['chat_id'=>config('services.telegram.chat_id')?:config('services.telegram.group_id'),'bot_token'=>config('services.telegram.bot_token'),'api_url'=>config('services.telegram.api_url'),'relay_url'=>config('services.telegram.relay_url'),'relay_secret'=>config('services.telegram.relay_secret')]);'''
    # Secret output goes straight into a host-only file, not command logs or arguments.
    result=subprocess.run(['docker','exec','arm-app','php','-r',php],capture_output=True,check=True,timeout=10)
    cfg=json.loads(result.stdout);assert cfg['chat_id'] and (cfg['bot_token'] or cfg['relay_url'])
    p=CONFIG/'notifications.json';p.write_text(json.dumps(cfg));p.chmod(0o600)
    for name in ['arm-metrics-collector.service','arm-metrics-collector.timer','arm-metrics-http.service','arm-monitoring-failure.service']:
        shutil.copyfile(a.source/name,Path('/etc/systemd/system')/name);(Path('/etc/systemd/system')/name).chmod(0o644)
    compose=['docker','compose','--project-directory',str(CONFIG),'--env-file',str(CONFIG/'images.env'),'-f',str(CONFIG/'compose.yml')]
    try:
        # Validate monitoring independently before touching Nginx.
        run(compose+['config','--quiet'],'compose-validate')
        run(['docker','run','--rm','--network','none','--cap-drop','ALL','--security-opt','no-new-privileges:true','-v',str(CONFIG)+':/etc/prometheus:ro','--entrypoint','/bin/promtool',images['prometheus'],'check','config','/etc/prometheus/prometheus.yml'],'promtool')
        with conf.open('wb') as f:f.write(old if already_marked else MARKER.encode()+old)
        conf.chmod(0o644)
        run(['docker','exec','arm-nginx','nginx','-t'],'nginx-new-test')
        run(['docker','exec','arm-nginx','nginx','-s','reload'],'nginx-reload')
        run(['systemctl','daemon-reload'],'systemd-reload')
        run(['systemctl','enable','--now','arm-metrics-http.service'],'metrics-http-start')
        run(['systemctl','start','arm-metrics-collector.service'],'collector-first',timeout=60)
        run(compose+['up','-d'],'monitor-start')
        run(['systemctl','enable','--now','arm-metrics-collector.timer'],'collector-timer')
        for attempt in range(30):
            try:
                with urllib.request.urlopen('http://127.0.0.1:3000/api/health',timeout=2) as r:assert json.load(r)['database']=='ok'
                with urllib.request.urlopen('http://127.0.0.1:9090/-/ready',timeout=2) as r:assert r.status==200
                break
            except Exception:
                if attempt==29:raise RuntimeError('Monitoring startup timed out')
                time.sleep(2)
        run(['curl','-fsS','--max-time','6','--resolve','www.xn---15-7edz.xn--p1ai:443:127.0.0.1','-o','/dev/null','https://www.xn---15-7edz.xn--p1ai/login'],'http-after')
        after=json.loads(run(['docker','inspect','--format','{{json .State}}','arm-app'],'app-state-after'))
        assert before['StartedAt']==after['StartedAt'] and after['Running']
        (backup/'success').write_text('Standalone monitoring installed. App not recreated.\n')
        print('MONITORING_INSTALLED\nBACKUP='+str(backup)+'\nACCESS=SSH tunnel to 127.0.0.1:3000\nPASSWORD_FILE=/etc/arm-monitoring/grafana-admin-password',flush=True)
    except BaseException:
        conf.write_bytes(old);conf.chmod(0o644)
        subprocess.run(['docker','exec','arm-nginx','nginx','-t'],capture_output=True)
        subprocess.run(['docker','exec','arm-nginx','nginx','-s','reload'],capture_output=True)
        subprocess.run(['systemctl','disable','--now','arm-metrics-collector.timer','arm-metrics-http.service'],capture_output=True)
        subprocess.run(compose+['stop'],capture_output=True)
        (backup/'rolled-back').write_text('Nginx restored; monitoring stopped; inspect before retry.\n')
        raise SystemExit('Install stopped/rolled back; private logs: '+str(backup))

if __name__=='__main__':main()
