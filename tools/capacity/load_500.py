#!/usr/bin/env python3
"""Authorized empty-server capacity test: 500 identities, populated month/chat.
Gradual open-loop arrival rate, at most 64 outstanding, strict stop and recovery.
"""
import argparse
import concurrent.futures
from collections import deque
from datetime import datetime, timezone
import http.client
import json
from pathlib import Path
import ssl
import socket
import resource
import read_smoke
import threading
import time
from read_smoke import metrics, guard, percentile


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--credentials',type=Path,required=True)
    p.add_argument('--output',type=Path,required=True)
    p.add_argument('--run',action='store_true')
    p.add_argument('--local-server',action='store_true')
    p.add_argument('--admission-100',action='store_true')
    p.add_argument('--admission-50',action='store_true')
    args=p.parse_args()
    if not args.run or args.credentials.stat().st_mode & 0o077:raise SystemExit('Explicit --run and private file required')
    if args.local_server:
        read_smoke.METRICS_URL='http://127.0.0.1:9108/metrics'
    class LoopbackHTTPS(http.client.HTTPSConnection):
        def connect(self):
            raw=socket.create_connection(('127.0.0.1',443),timeout=self.timeout)
            self.sock=self._context.wrap_socket(raw,server_hostname=self.host)
    connection_class=LoopbackHTTPS if args.local_server else http.client.HTTPSConnection
    fixture=json.loads(args.credentials.read_text());accounts=fixture['accounts'];room=fixture['room_id']
    if fixture['tag']!='capacity-20261109-500' or len(accounts)!=500:raise SystemExit('500 dedicated accounts required')
    report={'started_utc':datetime.now(timezone.utc).isoformat(),'result':'RUNNING','stages':[],'requests':[],
            'monitoring':[],'max_inflight':0,'scope':'500 driver identities; 15 shifts each; one shared synthetic 80-message work room',
            'transport':'Per-worker HTTPS keep-alive, <=64 connections; not 500 simultaneous TCP connections',
            'generator_on_server':args.local_server}
    if args.admission_100 and args.admission_50:raise SystemExit('Conflicting admission profiles')
    active_users=50 if args.admission_50 else (100 if args.admission_100 else 500)
    report['active_users_target']=active_users
    tls=threading.local();lock=threading.Lock();inflight=0;connections=[];last_message_id=None
    paths={'inbox':('GET','chats/sync'),'month':('GET','worktime/month?month=2026-11'),
           'thread':('GET',f'chats/{room}/messages?limit=50'),'ack':('POST',f'chats/{room}/read'),
           'documents':('GET','documents'),'topics':('GET','training/topics')}
    def request(slot,kind,phase):
        nonlocal inflight,last_message_id
        with lock:inflight+=1;report['max_inflight']=max(report['max_inflight'],inflight)
        start=time.monotonic();r={'phase':phase,'slot':slot,'kind':kind,'ok':False,'status':None,'started_utc':datetime.now(timezone.utc).isoformat()}
        try:
            if not hasattr(tls,'conn'):
                tls.conn=connection_class('www.xn---15-7edz.xn--p1ai',timeout=5,context=ssl.create_default_context())
                with lock:connections.append(tls.conn)
            method,path=paths[kind];body=None
            headers={'Authorization':'Bearer '+accounts[slot]['token'],'Accept':'application/json','User-Agent':'ARM-Capacity-500/1.0'}
            if kind=='ack':
                body=json.dumps({'last_read_message_id':last_message_id});headers['Content-Type']='application/json'
            tls.conn.request(method,'/api/mobile/'+path,body=body,headers=headers)
            response=tls.conn.getresponse();r['status']=response.status;raw=response.read(2*1024*1024+1)
            if len(raw)>2*1024*1024:raise ValueError('Oversize response')
            d=json.loads(raw);ok=response.status==200 and isinstance(d,dict)
            if ok and kind=='month':ok=d.get('month')=='2026-11' and len(d.get('shifts',[]))==15 and d.get('totals',{}).get('paid_minutes')==7200
            elif ok and kind=='inbox':ok=any(x.get('id')==room and x.get('last_message',{}).get('preview') for x in d.get('rooms',[]))
            elif ok and kind=='thread':
                messages=d.get('messages',[]);ok=len(messages)==50 and all(str(x.get('body','')).startswith('Capacity fixture message ') for x in messages)
                if ok:
                    with lock:last_message_id=max(x['id'] for x in messages)
            elif ok and kind=='ack':ok=d.get('ok') is True
            elif ok and kind=='documents':ok=isinstance(d.get('categories'),list)
            elif ok and kind=='topics':ok=isinstance(d.get('topics'),list)
            r['ok']=ok;r['bytes']=len(raw)
        except Exception as e:r['error']=type(e).__name__
        finally:
            r['seconds']=time.monotonic()-start
            with lock:inflight-=1
        return r
    pending=set()
    try:
        initial=metrics();guard(initial,initial);report['monitoring'].append(initial)
        # Validate populated data, decryption and read acknowledgements before ramping.
        for kind in ['month','inbox','thread','ack','documents','topics']:
            r=request(0,kind,'preflight');report['requests'].append(r)
            if not r['ok']:raise RuntimeError('Populated preflight failed: '+kind+' status='+str(r['status']))
            time.sleep(.5)
        print('PREFLIGHT_OK populated_month=15 chat_messages=50 ack=ok',flush=True)
        plan=[('mixed-2',2,45,'mixed'),('mixed-5',5,45,'mixed'),('mixed-10',10,60,'mixed'),('mixed-20',20,60,'mixed'),
              ('500-foreground',38.34,150,'mixed'),('open-chat-60',60,60,'open'),('500-open-chat',100,120,'open')]
        if args.admission_100:
            plan=[('admission-warm-2',2,30,'mixed'),('admission-warm-5',5,30,'mixed'),('100-active-mixed',7.67,300,'mixed')]
        if args.admission_50:
            plan=[('admission-50-warm',2,30,'mixed'),('50-active-mixed',3.834,300,'mixed')]
        counters={k:0 for k in paths};sequence=0
        with concurrent.futures.ThreadPoolExecutor(max_workers=64) as pool:
            for name,rate,duration,profile in plan:
                stage={'name':name,'target_rps':rate,'duration_target':duration,'profile':profile,'started_utc':datetime.now(timezone.utc).isoformat(),'requests':0}
                report['stages'].append(stage);cpu_before=resource.getrusage(resource.RUSAGE_SELF);start=time.monotonic();next_request=start;next_monitor=start;next_progress=start+20;next_latency=start+10
                pending=set();window=deque();values=[];bad_latency_windows=0;unique=set();by_kind={};sent=0
                while time.monotonic()-start<duration or pending:
                    now=time.monotonic()
                    if now>=next_monitor:
                        m=metrics();m.update({'stage':name,'elapsed':now-start});report['monitoring'].append(m);guard(m,initial);next_monitor=time.monotonic()+10
                    if now>=next_latency:
                        while window and now-window[0][0]>20:window.popleft()
                        if len(window)>=20:
                            p95=percentile([x[1] for x in window],.95)
                            bad_latency_windows=bad_latency_windows+1 if p95>1 else 0
                            if bad_latency_windows>=2:raise RuntimeError('Sustained client p95 >1s')
                        next_latency=now+10
                    if now>=next_progress:
                        print('PROGRESS '+json.dumps({'stage':name,'completed':stage['requests'],'pending':len(pending),'elapsed':round(now-start,1)}),flush=True);next_progress=now+20
                    if now-start<duration and now>=next_request:
                        if len(pending)>=64:raise RuntimeError('Generator inflight cap reached; not a proven server limit')
                        if profile=='open':kind=['inbox','thread','ack'][sequence%3]
                        else:
                            pos=sequence%100
                            kind='inbox' if pos<87 else ('month' if pos<93 else ('thread' if pos<96 else ('documents' if pos<98 else 'topics')))
                        slot=counters[kind]%active_users;counters[kind]+=1;sequence+=1
                        pending.add(pool.submit(request,slot,kind,name));sent+=1
                        # Bounded catch-up: at most one dispatch per iteration, never a burst of overdue tasks.
                        next_request=max(next_request+1/rate,now)
                    for f in list(pending):
                        if f.done():
                            r=f.result();pending.remove(f);report['requests'].append(r);stage['requests']+=1
                            values.append(r['seconds']);window.append((time.monotonic(),r['seconds']));unique.add(r['slot'])
                            by_kind[r['kind']]=by_kind.get(r['kind'],0)+1
                            if not r['ok']:raise RuntimeError('Invalid response: '+r['kind']+' status='+str(r['status'])+' error='+str(r.get('error')))
                            if r['seconds']>5:raise RuntimeError('Client request >5s')
                    time.sleep(.002)
                stage.update({'elapsed':time.monotonic()-start,'unique_users':len(unique),'by_kind':by_kind,
                              'p50':percentile(values,.5),'p95':percentile(values,.95),'p99':percentile(values,.99),'max':max(values),
                              'finished_utc':datetime.now(timezone.utc).isoformat()})
                cpu_after=resource.getrusage(resource.RUSAGE_SELF)
                stage['generator_cpu_seconds']=(cpu_after.ru_utime+cpu_after.ru_stime)-(cpu_before.ru_utime+cpu_before.ru_stime)
                stage['observed_rps']=stage['requests']/stage['elapsed'];stage['result']='PASS'
                print('STAGE_OK '+json.dumps(stage),flush=True)
                for _ in range(2):time.sleep(10);m=metrics();m['stage']='cooldown';report['monitoring'].append(m);guard(m,initial)
        report['result']='PASS'
    except (Exception,KeyboardInterrupt) as e:
        report['result']='STOPPED';report['reason']=str(e) if isinstance(e,RuntimeError) else type(e).__name__
        # The executor drains already dispatched work on exit; record it without sending retries.
        for future in list(pending):
            if future.done() and not future.cancelled():
                try:
                    row=future.result();row['drained_after_stop']=True;report['requests'].append(row)
                except Exception:pass
        if report['stages'] and 'result' not in report['stages'][-1]:
            report['stages'][-1]['result']='STOPPED'
            cpu_after=resource.getrusage(resource.RUSAGE_SELF)
            report['stages'][-1]['generator_cpu_seconds']=(cpu_after.ru_utime+cpu_after.ru_stime)-(cpu_before.ru_utime+cpu_before.ru_stime)
            report['stages'][-1]['elapsed']=time.monotonic()-start
    finally:
        for conn in connections:conn.close()
        report['finished_utc']=datetime.now(timezone.utc).isoformat();report['connections_created']=len(connections)
        args.output.write_text(json.dumps(report,indent=2))
    print(json.dumps({'result':report['result'],'reason':report.get('reason'),'requests':len(report['requests']),'max_inflight':report['max_inflight']}),flush=True)
    return 0 if report['result']=='PASS' else 1


if __name__=='__main__':raise SystemExit(main())
