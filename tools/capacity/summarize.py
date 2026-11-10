#!/usr/bin/env python3
"""Summarize a sanitized read_smoke result; never needs credentials."""
import json
from pathlib import Path
import sys


def percentile(values, p):
    import math
    values=sorted(values)
    return values[max(0,math.ceil(len(values)*p)-1)] if values else None


def summarize(path):
    data=json.loads(Path(path).read_text())
    result={'result':data['result'],'reason':data.get('reason'),'transport':data.get('transport','new HTTPS connection/request'),
            'started_utc':data['started_utc'],'finished_utc':data['finished_utc'],'requests':len(data['requests']),
            'successes':sum(r['ok'] for r in data['requests']),'max_inflight':data['max_inflight'],'stages':data['stages'],'endpoints':{}}
    for name in sorted({r['endpoint'] for r in data['requests']}):
        rows=[r for r in data['requests'] if r['endpoint']==name and r['phase']!='preflight']
        values=[r['seconds'] for r in rows]
        result['endpoints'][name]={'requests':len(rows),'successes':sum(r['ok'] for r in rows),
                                   'p50':percentile(values,.5),'p95':percentile(values,.95),'max':max(values) if values else None}
    samples=data['monitoring']
    if samples:
        result['server']={'cpu_max':max(m['cpu'] for m in samples),'available_ram_ratio_min':min(m['available_ratio'] for m in samples),
                          'db_connections_max':max(m['db_connections'] for m in samples),
                          'queue_failed_max':max(m['failed'] for m in samples),
                          'new_5xx':max(m['http_5xx'] for m in samples)-samples[0]['http_5xx']}
    return result


if __name__=='__main__':
    print(json.dumps(summarize(sys.argv[1]),indent=2))
