#!/usr/bin/env python3
"""Read-only microbursts: 2/3/5 parallel requests, <=1 average test request/s.
No retries, no response bodies or credentials in reports. Not a saturation test.
"""
import argparse
import concurrent.futures
from datetime import datetime, timezone
import http.client
import json
from pathlib import Path
import ssl
import threading
import time
from read_smoke import TARGETS, metrics, guard, percentile


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--credentials',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--run',action='store_true')
    args=parser.parse_args()
    if not args.run or args.credentials.stat().st_mode & 0o077:
        raise SystemExit('Explicit --run and private credentials required')
    private=json.loads(args.credentials.read_text())
    accounts=private['accounts']
    if len(accounts)!=5 or private['tag']!='capacity-20261109-concurrent':
        raise SystemExit('Require five dedicated concurrent test accounts')
    connections=[http.client.HTTPSConnection('www.xn---15-7edz.xn--p1ai',timeout=5,context=ssl.create_default_context()) for _ in accounts]
    report={'started_utc':datetime.now(timezone.utc).isoformat(),'result':'RUNNING','stages':[],
            'requests':[],'monitoring':[],'max_inflight':0,'scope':'2/3/5 concurrent read microbursts, average <=1 rps, empty driver profiles'}
    mutex=threading.Lock()
    inflight=0
    def request(account_index,target_index,phase,barrier=None):
        nonlocal inflight
        if barrier is not None:
            barrier.wait(timeout=5)
        start=time.monotonic()
        with mutex:
            inflight+=1;report['max_inflight']=max(report['max_inflight'],inflight)
        path,key,kind=TARGETS[target_index%len(TARGETS)]
        result={'phase':phase,'endpoint':path.split('?')[0],'ok':False,'status':None}
        try:
            conn=connections[account_index]
            conn.request('GET','/api/mobile/'+path,headers={'Authorization':'Bearer '+accounts[account_index]['token'],
                         'Accept':'application/json','User-Agent':'ARM-Capacity-Concurrent-Read/1.0'})
            response=conn.getresponse();result['status']=response.status
            raw=response.read(2*1024*1024+1)
            if len(raw)>2*1024*1024:raise ValueError('Oversize response')
            data=json.loads(raw)
            result['ok']=response.status==200 and isinstance(data,dict) and isinstance(data.get(key),kind)
            if path.startswith('worktime/'):
                result['ok']=result['ok'] and data.get('month')=='2026-11' and isinstance(data.get('totals'),dict)
            result['bytes']=len(raw)
            result['items']=len(data[key]) if result['ok'] else None
        except Exception as error:
            result['error']=type(error).__name__
        finally:
            result['seconds']=time.monotonic()-start
            with mutex:inflight-=1
        return result
    try:
        initial=metrics();guard(initial,initial);report['monitoring'].append(initial)
        for i in range(25):
            start=time.monotonic();r=request(i%5,i//5,'preflight');report['requests'].append(r)
            if not r['ok']:raise RuntimeError('Preflight response invalid')
            time.sleep(max(0,1-(time.monotonic()-start)))
        print('PREFLIGHT_OK pairs=25',flush=True)
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            batch=0
            for size in [2,3,5]:
                stage={'parallel_target':size,'duration_target':90,'requests':0,'batches':0,'interval_seconds':size*1.1}
                report['stages'].append(stage)
                stage_start=time.monotonic();next_batch=stage_start;next_monitor=stage_start
                values=[]
                while time.monotonic()-stage_start<90:
                    now=time.monotonic()
                    if now>=next_monitor:
                        m=metrics();report['monitoring'].append(m);guard(m,initial);next_monitor=time.monotonic()+15
                    if now<next_batch:
                        time.sleep(min(.1,next_batch-now));continue
                    barrier=threading.Barrier(size)
                    start=time.monotonic()
                    # One request per account in a batch: HTTPSConnection is never shared concurrently.
                    futures=[pool.submit(request,(batch+i)%5,(batch+i)%len(TARGETS),size,barrier) for i in range(size)]
                    rows=[f.result() for f in futures]
                    report['requests'].extend(rows);values.extend(r['seconds'] for r in rows)
                    stage['requests']+=size;stage['batches']+=1
                    if any(not r['ok'] for r in rows):raise RuntimeError('Invalid response in concurrent batch')
                    if any(r['seconds']>1 for r in rows):raise RuntimeError('Concurrent request exceeded 1s threshold')
                    # Do not catch up by firing several delayed batches together.
                    next_batch=max(start+size*1.1,time.monotonic())
                    batch+=1
                stage['elapsed']=time.monotonic()-stage_start
                stage['observed_rpm']=stage['requests']/stage['elapsed']*60
                stage.update({'p50':percentile(values,.5),'p95':percentile(values,.95),'p99':percentile(values,.99),'max':max(values)})
                print('STAGE_OK '+json.dumps(stage),flush=True)
                for _ in range(2):
                    time.sleep(10);m=metrics();report['monitoring'].append(m);guard(m,initial)
        report['result']='PASS'
    except (Exception,KeyboardInterrupt) as error:
        report['result']='STOPPED';report['reason']=str(error) if isinstance(error,RuntimeError) else type(error).__name__
    finally:
        for connection in connections:connection.close()
        report['finished_utc']=datetime.now(timezone.utc).isoformat()
        args.output.write_text(json.dumps(report,indent=2))
    print(json.dumps({'result':report['result'],'reason':report.get('reason'),'requests':len(report['requests']),
                      'max_inflight':report['max_inflight']}),flush=True)
    return 0 if report['result']=='PASS' else 1


if __name__=='__main__':
    raise SystemExit(main())
