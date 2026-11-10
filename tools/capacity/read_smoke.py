#!/usr/bin/env python3
"""Bounded ARM production read test. Requires private, expiring test tokens.
Fixed total cap 1 request/s, at most 5 in flight. Never prints response bodies/tokens.
"""
import argparse
import concurrent.futures
from datetime import datetime, timezone
import json
import http.client
import ssl
import math
from pathlib import Path
import re
import threading
import time
import urllib.error
import urllib.request

METRICS_URL = 'http://127.0.0.1:19108/metrics'
BASE = 'https://www.xn---15-7edz.xn--p1ai/api/mobile'
TARGETS = [('worktime/month?month=2026-11', 'shifts', list),
           ('documents', 'categories', list), ('naryads', 'naryads', list),
           ('training/topics', 'topics', list), ('chats/sync', 'rooms', list)]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def percentile(values, p):
    if not values:
        return None
    ordered = sorted(values)
    return ordered[max(0, math.ceil(len(ordered)*p)-1)]


def metrics():
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(METRICS_URL, timeout=3) as response:
        body = response.read(256*1024).decode()
    rows = {}
    for line in body.splitlines():
        m = re.fullmatch(r'(arm_[A-Za-z0-9_]+)(\{[^}]*\})?\s+([-+0-9.eE]+)', line)
        if m:
            rows[(m[1], m[2] or '')] = float(m[3])
    def val(name, label=''):
        return rows[(name, label)]
    result = {'cpu': val('arm_host_cpu_percent'),
              'available_ratio': val('arm_host_memory_bytes', '{kind="MemAvailable"}')/val('arm_host_memory_bytes', '{kind="MemTotal"}'),
              'failed': val('arm_queue_failed'), 'pending': val('arm_queue_pending'),
              'queue_age': val('arm_queue_oldest_age'),
              'db_connections': val('arm_db_connections'), 'db_limit': val('arm_db_max_connections'),
              'db_slow': val('arm_db_slow'),
              'age': time.time()-val('arm_collector_timestamp_seconds'),
              'http_5xx': val('arm_http_requests_total', '{status_class="5xx"}'),
              'probe_up': val('arm_http_probe_up')}
    result['stages_ok'] = all(val('arm_collection_stage_up', '{stage="'+s+'"}') == 1 for s in ['docker','database','http','nginx_logs'])
    return result


def guard(m, initial):
    if not m['stages_ok'] or m['age'] > 90 or m['probe_up'] != 1:
        raise RuntimeError('Monitoring unhealthy or stale')
    if m['cpu'] > 70 or m['available_ratio'] < 0.2:
        raise RuntimeError('CPU/RAM stop threshold')
    if m['db_connections'] > 0.5*m['db_limit'] or m['db_slow'] > 0:
        raise RuntimeError('Database stop threshold')
    if m['failed'] > initial['failed'] or m['queue_age'] > 60 or m['pending'] > initial['pending']+10:
        raise RuntimeError('Queue stop threshold')
    if m['http_5xx'] > initial['http_5xx']:
        raise RuntimeError('New server 5xx observed')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--credentials', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--run', action='store_true')
    args = parser.parse_args()
    if not args.run:
        raise SystemExit('Explicit --run required')
    if args.credentials.stat().st_mode & 0o077:
        raise SystemExit('Credentials must be private (0600)')
    private = json.loads(args.credentials.read_text())
    accounts = private['accounts']
    if len(accounts) != 5 or not private['tag'].startswith('capacity-'):
        raise SystemExit('Require five dedicated capacity accounts')
    report = {'started_utc': datetime.now(timezone.utc).isoformat(),
              'scope': 'Five driver accounts, empty shifts/chat, listing API only; no login/PDF/writes/browser rendering',
              'stages': [], 'monitoring': [], 'requests': [], 'max_inflight': 0, 'transport': 'HTTPS keep-alive per account'}
    mutex = threading.Lock()
    inflight = 0
    connections = [http.client.HTTPSConnection("www.xn---15-7edz.xn--p1ai", timeout=5, context=ssl.create_default_context()) for _ in accounts]
    connection_locks = [threading.Lock() for _ in accounts]
    def request(index, phase):
        nonlocal inflight
        account = accounts[index % 5]
        path, key, kind = TARGETS[(index // 5) % len(TARGETS)]
        started = time.monotonic()
        result = {'phase': phase, 'endpoint': path.split('?')[0], 'status': None, 'ok': False}
        with mutex:
            inflight += 1
            report['max_inflight'] = max(report['max_inflight'], inflight)
        try:
            with connection_locks[index % 5]:
                connection = connections[index % 5]
                connection.request('GET', '/api/mobile/'+path, headers={'Authorization': 'Bearer '+account['token'],
                    'Accept': 'application/json', 'User-Agent':'ARM-Capacity-Read-Smoke/1.0'})
                response = connection.getresponse()
                result['status'] = response.status
                raw = response.read(2*1024*1024+1)
                if len(raw) > 2*1024*1024:
                    raise ValueError('Oversize response')
                data = json.loads(raw)
                result['ok'] = response.status == 200 and isinstance(data, dict) and isinstance(data.get(key),kind)
                if path.startswith('worktime/'):
                    result['ok'] = result['ok'] and data.get('month') == '2026-11' and isinstance(data.get('totals'),dict)
                result['items'] = len(data[key]) if result['ok'] else None
                result['bytes'] = len(raw)
        except urllib.error.HTTPError as error:
            result['status'] = error.code
            result['error'] = 'HTTPError'
        except Exception as error:
            result['error'] = type(error).__name__
        finally:
            result['seconds'] = time.monotonic()-started
            with mutex:
                inflight -= 1
        return result
    try:
        initial = metrics()
        guard(initial, initial)
        report['monitoring'].append(initial)
        # Validate every account/endpoint pair once before the timed stages, at <=1 req/s.
        for i in range(25):
            start = time.monotonic()
            r = request(i,'preflight')
            report['requests'].append(r)
            if not r['ok']:
                raise RuntimeError('Preflight failed: '+r['endpoint']+' status='+str(r['status']))
            time.sleep(max(0,1-(time.monotonic()-start)))
        print('PREFLIGHT_OK accounts=5 endpoint_pairs=25', flush=True)
        guard(metrics(), initial)
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            index = 0
            for rate in [0.2,0.5,1.0]:
                stage = {'rps_cap':rate,'duration_target':120,'requests':0}
                report['stages'].append(stage)
                start = time.monotonic()
                next_request = start
                next_monitor = start
                pending = set()
                while time.monotonic()-start < 120 or pending:
                    now = time.monotonic()
                    if now >= next_monitor:
                        m = metrics()
                        m['elapsed_stage'] = now-start
                        m['rps_cap'] = rate
                        report['monitoring'].append(m)
                        guard(m,initial)
                        next_monitor = time.monotonic()+15
                    if now-start < 120 and now >= next_request:
                        if len(pending) >= 5:
                            raise RuntimeError('Inflight cap reached')
                        pending.add(pool.submit(request,index,rate))
                        index += 1
                        next_request = now+1/rate
                    for future in list(pending):
                        if future.done():
                            r = future.result()
                            pending.remove(future)
                            report['requests'].append(r)
                            stage['requests'] += 1
                            if not r['ok']:
                                raise RuntimeError('Read failed: '+r['endpoint']+' status='+str(r['status']))
                            if r['seconds'] > 1:
                                raise RuntimeError('Client latency exceeded conservative 1s threshold')
                    time.sleep(0.05)
                stage['elapsed'] = time.monotonic()-start
                values = [r['seconds'] for r in report['requests'] if r['phase'] == rate]
                stage.update({'p50':percentile(values,.5),'p95':percentile(values,.95),'p99':percentile(values,.99)})
                print('STAGE_OK '+json.dumps(stage),flush=True)
                # Drain and monitor after each stage; no immediate jump if metrics lag.
                for _ in range(3):
                    time.sleep(10)
                    m=metrics();report['monitoring'].append(m);guard(m,initial)
        report['result']='PASS'
    except (Exception, KeyboardInterrupt) as error:
        report['result']='STOPPED'
        report['reason']=str(error) if isinstance(error,RuntimeError) else type(error).__name__
    finally:
        for connection in connections:
            connection.close()
        report['finished_utc']=datetime.now(timezone.utc).isoformat()
        args.output.write_text(json.dumps(report,indent=2))
    print(json.dumps({'result':report['result'],'reason':report.get('reason'),'requests':len(report['requests']),
                      'max_inflight':report['max_inflight'],'output':str(args.output)}),flush=True)
    return 0 if report['result']=='PASS' else 1


if __name__ == '__main__':
    raise SystemExit(main())
