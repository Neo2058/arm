#!/usr/bin/env python3
"""Root-owned bounded operational collector. No user events or production writes.

Outputs only fixed aggregate labels. No Docker socket is exposed to monitoring containers.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import fcntl
import http.server
import json
import math
import os
from pathlib import Path
import re
import shutil
import subprocess
import time
import urllib.request

BASE = Path('/var/lib/arm-monitoring')
PRIVATE = BASE/'private'
METRICS = BASE/'metrics/arm.prom'
SERVICES = ('arm-app','arm-nginx','arm-postgres','arm-redis','arm-minio','arm-clickhouse','arm-meilisearch','arm-clamav-1')
BUCKETS = (.05,.1,.25,.5,1,2.5,5,10,30,float('inf'))


def run(args, timeout=8):
    return subprocess.check_output(args, stderr=subprocess.DEVNULL, timeout=timeout).decode()


def atomic(path, value, mode=0o600):
    temp = path.with_suffix(path.suffix+'.next')
    fd = os.open(temp, os.O_WRONLY|os.O_CREAT|os.O_TRUNC, mode)
    with os.fdopen(fd,'w') as f:
        f.write(value)
        f.flush()
        os.fsync(f.fileno())
    temp.chmod(mode)
    os.replace(temp,path)


def parse_nginx(text, since, until):
    counts=Counter(); buckets=Counter(); total=0.; n=0
    for line in text.splitlines():
        m=re.fullmatch(r'arm_metrics (\d+\.\d+) ([1-5]\d\d) (\d+(?:\.\d+)?)',line.strip())
        if not m or not since<=float(m[1])<until:
            continue
        duration=float(m[3])
        counts[m[2][0]+'xx']+=1; total+=duration; n+=1
        for b in BUCKETS:
            if duration<=b: buckets['+Inf' if math.isinf(b) else str(b)]+=1
    return counts,buckets,total,n


def cpu_usage(previous, current):
    if not previous: return 0.
    delta=sum(current)-sum(previous)
    idle=(current[3]+current[4])-(previous[3]+previous[4])
    return min(100.,max(0.,100*(1-idle/delta))) if delta>0 else 0.


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None


def notify(message):
    cfg=json.loads(Path('/etc/arm-monitoring/notifications.json').read_text())
    payload={'chat_id':cfg['chat_id'],'text':message,'protect_content':True}
    relay=cfg.get('relay_url')
    if relay:
        if not (relay.startswith('https://') or re.match(r'^http://(?:127\.0\.0\.1|localhost|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+)(?::\d+)?/',relay)):
            raise ValueError('invalid relay transport')
        url=relay;payload.update(method='sendMessage',secret=cfg['relay_secret'])
    else:
        url=cfg['api_url'].rstrip('/')+'/bot'+cfg['bot_token']+'/sendMessage'
        if not url.startswith('https://'):raise ValueError('HTTPS required')
    request=urllib.request.Request(url,data=json.dumps(payload).encode(),headers={'Content-Type':'application/json'})
    with urllib.request.build_opener(NoRedirect).open(request,timeout=8) as response:
        if json.load(response).get('ok') is not True:raise ValueError('notification rejected')


PHP = r'''
require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::statement("SET statement_timeout = '1500ms'");
$r=DB::selectOne("SELECT count(*) AS connections, count(*) FILTER (WHERE state='active' AND pid<>pg_backend_pid()) AS active, count(*) FILTER (WHERE state='active' AND pid<>pg_backend_pid() AND query_start<now()-interval '5 seconds') AS slow FROM pg_stat_activity WHERE datname=current_database()");
$max=DB::selectOne('SHOW max_connections');
$out=['db_connections'=>(int)$r->connections,'db_active'=>(int)$r->active,'db_slow'=>(int)$r->slow,'db_max_connections'=>(int)$max->max_connections,'queue_pending'=>(int)DB::table('jobs')->count(),'queue_failed'=>(int)DB::table('failed_jobs')->count()];
$out['queue_oldest_age']=max(0,time()-(DB::table('jobs')->min('created_at')?:time()));
echo json_encode($out);
'''.replace('DB::','Illuminate\\Support\\Facades\\DB::')


def collect():
    now=time.time(); started=time.monotonic()
    state_path=PRIVATE/'state.json'
    state=json.loads(state_path.read_text()) if state_path.exists() else {}
    metrics=[]; issues={}; stages={}
    def emit(name,value,**labels):
        if not isinstance(value,(int,float)) or not math.isfinite(value):return
        label='{'+','.join(k+'='+json.dumps(v) for k,v in sorted(labels.items()))+'}' if labels else ''
        metrics.append(name+label+' '+str(value))
    cpu=[int(x) for x in Path('/proc/stat').read_text().splitlines()[0].split()[1:9]]
    pct=cpu_usage(state.get('cpu'),cpu);state['cpu']=cpu;emit('arm_host_cpu_percent',pct)
    mem=dict((m[1],int(m[2])*1024) for line in Path('/proc/meminfo').read_text().splitlines() if (m:=re.match(r'^(\w+):\s+(\d+) kB',line)))
    for key in ['MemTotal','MemAvailable','SwapTotal','SwapFree']:emit('arm_host_memory_bytes',mem.get(key,0),kind=key)
    disk=shutil.disk_usage('/');emit('arm_host_disk_bytes',disk.total,kind='total');emit('arm_host_disk_bytes',disk.free,kind='free')
    for index,value in enumerate(os.getloadavg()):emit('arm_host_load',value,window=('1m','5m','15m')[index])
    emit('arm_host_cpu_count',os.cpu_count() or 1)
    rx=tx=0
    for line in Path('/proc/net/dev').read_text().splitlines()[2:]:
        iface,values=line.split(':',1)
        if re.match(r'^(eth|ens|enp)',iface.strip()):
            parts=values.split();rx+=int(parts[0]);tx+=int(parts[8])
    emit('arm_host_network_receive_bytes_total',rx);emit('arm_host_network_transmit_bytes_total',tx)
    rd=wr=io=0
    for line in Path('/proc/diskstats').read_text().splitlines():
        parts=line.split()
        if re.fullmatch(r'(?:[vs]d[a-z]+|nvme\d+n\d+)',parts[2]):
            rd+=int(parts[5])*512;wr+=int(parts[9])*512;io+=int(parts[12])/1000
    emit('arm_host_disk_read_bytes_total',rd);emit('arm_host_disk_write_bytes_total',wr);emit('arm_host_disk_io_seconds_total',io)
    stages['docker']=0
    try:
        info=json.loads(run(['docker','inspect',*SERVICES]))
        # Expose no Config/Env/Mounts, even transiently in metrics.
        for item in info:
            name=item['Name'].lstrip('/')
            running=item['State'].get('Running',False)
            healthy=item['State'].get('Health',{}).get('Status','healthy')=='healthy'
            emit('arm_container_up',int(running),service=name)
            emit('arm_container_healthy',int(running and healthy),service=name)
            emit('arm_container_restarts',item['RestartCount'],service=name)
            if not running or not healthy:issues['service_'+name]='Сервис '+name+' недоступен или нездоров.'
        stats=run(['docker','stats','--no-stream','--format','{{json .}}',*SERVICES],timeout=6)
        for line in stats.splitlines():
            item=json.loads(line);name=item['Name']
            emit('arm_container_cpu_percent',float(item['CPUPerc'].rstrip('%')),service=name)
            emit('arm_container_memory_percent',float(item['MemPerc'].rstrip('%')),service=name)
        processes=run(['docker','top','arm-app','-eo','pid,args'],timeout=2)
        emit('arm_fpm_workers',sum('php-fpm: pool ' in line for line in processes.splitlines()))
        stages['docker']=1
    except Exception:issues['docker']='Не удалось проверить контейнеры.'
    # TLS request hits only local Nginx; no public DNS dependence or user writes.
    try:
        result=run(['curl','--silent','--show-error','--max-time','4','--resolve','www.xn---15-7edz.xn--p1ai:443:127.0.0.1','-o','/dev/null','-w','%{http_code} %{time_total}','https://www.xn---15-7edz.xn--p1ai/login'],timeout=5).split()
        status=int(result[0]);elapsed=float(result[1]);emit('arm_http_probe_up',int(status==200));emit('arm_http_probe_duration_seconds',elapsed);stages['http']=1
        if status!=200:issues['web']='Веб-вход не отвечает HTTP 200.'
        if elapsed>3:issues['web_slow']='Проверка веб-входа занимает больше 3 секунд.'
    except Exception:emit('arm_http_probe_up',0);stages['http']=0;issues['web']='Проверка веб-входа недоступна.'
    try:
        data=json.loads(run(['docker','exec','-u','www-data','arm-app','php','-r',PHP],timeout=5))
        for key,value in data.items():emit('arm_'+key,value)
        stages['database']=1
        if data['queue_failed']>0:issues['failed_jobs']='В очереди есть неуспешные задания.'
        if data['queue_oldest_age']>300:issues['queue_lag']='Задания очереди ожидают больше 5 минут.'
        if data['db_connections']>.8*data['db_max_connections']:issues['db_capacity']='Использовано больше 80% соединений БД.'
        if data['db_slow']>0:issues['db_slow']='В БД есть активные запросы дольше 5 секунд.'
    except Exception:stages['database']=0;issues['database']='Не удалось прочитать агрегаты БД и очередей.'
    last=state.get('log_until',now-30)
    totals=state.setdefault('http_counts',{});hist=state.setdefault('http_buckets',{})
    try:
        log=run(['docker','logs','--since',str(last),'--until',str(now),'--tail','5000','arm-nginx'],timeout=3)
        counts,buckets,total,n=parse_nginx(log,last,now)
        for k,v in counts.items():totals[k]=totals.get(k,0)+v
        for k,v in buckets.items():hist[k]=hist.get(k,0)+v
        state['http_duration_sum']=state.get('http_duration_sum',0)+total
        state['http_duration_count']=state.get('http_duration_count',0)+n
        state['log_until']=now;stages['nginx_logs']=1
        emit('arm_nginx_log_tail_limit_hit',int(len(log.splitlines())>=5000))
        if n>=10 and counts['5xx']/n>.05:issues['http_errors']='Больше 5% учтённых HTTP-запросов завершились ошибкой 5xx.'
    except Exception:stages['nginx_logs']=0;issues['nginx_logs']='Не удалось прочитать агрегаты HTTP.'
    for cls in ['1xx','2xx','3xx','4xx','5xx']:emit('arm_http_requests_total',totals.get(cls,0),status_class=cls)
    for b in BUCKETS:
        label='+Inf' if math.isinf(b) else str(b);emit('arm_http_request_duration_seconds_bucket',hist.get(label,0),le=label)
    emit('arm_http_request_duration_seconds_sum',state.get('http_duration_sum',0));emit('arm_http_request_duration_seconds_count',state.get('http_duration_count',0))
    for name,url in [('grafana','http://127.0.0.1:3000/api/health'),('prometheus','http://127.0.0.1:9090/-/ready'),('metrics_http','http://127.0.0.1:9108/metrics')]:
        try:
            with urllib.request.urlopen(url,timeout=3) as response:ok=response.status==200
        except Exception:ok=False
        emit('arm_monitor_service_up',int(ok),service=name)
        if not ok:issues['monitor_'+name]='Служба мониторинга '+name+' недоступна.'
    receipts=sorted(Path('/var/lib/arm-backups').glob('arm-full-*.age.json'))
    backup_time=0
    for p in receipts[-3:]:
        try:
            r=json.loads(p.read_text());archive=p.with_suffix('')
            if r.get('postgres_restore_test')=='PASS' and archive.is_file():backup_time=max(backup_time,p.stat().st_mtime)
        except Exception:pass
    emit('arm_backup_created_timestamp_seconds',backup_time)
    if now-backup_time>72*3600:issues['backup_old']='Серверная резервная копия старше 72 часов или не найдена.'
    if pct>85:issues['cpu']='CPU занят больше чем на 85%.'
    if mem['MemAvailable']<mem['MemTotal']*.1:issues['memory']='Доступно меньше 10% оперативной памяти.'
    if disk.free<disk.total*.15:issues['disk']='Свободно меньше 15% диска.'
    alerts=state.setdefault('alerts',{});messages=[]
    for name in set(alerts)|set(issues):
        entry=alerts.setdefault(name,{})
        if name in issues:
            entry.setdefault('since',now)
            active=now-entry['since']>=(600 if name=='backup_old' else 120)
            emit('arm_alert_active',int(active),alert=name)
            if active and now-entry.get('sent',0)>21600:messages.append((name,issues[name]))
        else:
            if entry.get('sent'):messages.append((name,'Восстановлено: '+name+'.'))
            entry.pop('since',None);emit('arm_alert_active',0,alert=name)
    configured=Path('/etc/arm-monitoring/notifications.json').exists();emit('arm_notifications_configured',int(configured))
    if messages and configured:
        try:
            notify('ARM · мониторинг\n'+'\n'.join(text for _,text in messages))
            state['notification_last_success']=now
            for name,_ in messages:
                if name in issues:alerts[name]['sent']=now
                else:alerts.pop(name,None)
        except Exception:pass  # Retry without leaking token-bearing transport exceptions.
    emit('arm_notification_last_success_timestamp_seconds',state.get('notification_last_success',0))
    for stage,success in stages.items():emit('arm_collection_stage_up',success,stage=stage)
    emit('arm_collector_timestamp_seconds',now);emit('arm_collector_duration_seconds',time.monotonic()-started)
    atomic(state_path,json.dumps(state))
    atomic(METRICS,'\n'.join(metrics)+'\n',0o644)


def serve():
    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            if self.path!='/metrics':self.send_error(404);return
            try:data=METRICS.read_bytes()
            except OSError:self.send_error(503);return
            self.send_response(200);self.send_header('Content-Type','text/plain; version=0.0.4');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
        def log_message(self,*args):pass
    http.server.ThreadingHTTPServer(('127.0.0.1',9108),Handler).serve_forever()


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--serve',action='store_true');parser.add_argument('--notify-test',action='store_true');parser.add_argument('--notify-failure',action='store_true');a=parser.parse_args()
    if a.serve:serve()
    else:
        if os.geteuid()!=0:raise SystemExit('Collector must run through reviewed root-owned systemd service.')
        if a.notify_failure:notify('ARM · мониторинг: сборщик метрик завершился ошибкой. Проверьте systemd и свежесть панели.')
        elif a.notify_test:notify('ARM · мониторинг подключён. Тест доставки уведомлений.');print('NOTIFICATION_DELIVERED')
        else:
            PRIVATE.mkdir(parents=True,exist_ok=True,mode=0o700)
            with (PRIVATE/'collector.lock').open('w') as lock:
                fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);collect()
