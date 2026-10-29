#!/usr/bin/env python3
"""Remove public BIND listeners while retaining loopback and current Docker bridges.

Only for the audited host with no application DNS zones. Does not alter resolvers,
firewall, Docker, mail, FTP or SSH. Requires external checks before timer cancellation.
"""
import argparse
from datetime import datetime
import ipaddress
import json
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import time


def run(*args, check=True):
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=30)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if not args.apply or os.geteuid() != 0:
        raise SystemExit('Requires root and explicit --apply after DNS dependency audit.')
    os.umask(0o077)
    target = Path('/etc/bind/named.conf.options')
    original = target.read_text()
    if '// ARM private DNS listeners' in original:
        raise SystemExit('Already managed: inspect instead of applying twice.')
    assert run('systemctl', 'is-active', 'named').stdout.strip() == 'active'
    effective = run('named-checkconf', '-p').stdout
    zones = set(re.findall(r'^zone "([^"]+)"', effective, re.M))
    assert zones == {'.', 'localhost', '127.in-addr.arpa', '0.in-addr.arpa',
                     '255.in-addr.arpa'}, 'Non-default zones require separate review'
    assert 'listen-on {' not in original and 'listen-on port' not in original
    assert original.count('listen-on-v6 { any; };') == 1
    interfaces = json.loads(run('ip', '-j', 'address').stdout)
    addresses = ['127.0.0.1']
    for interface in interfaces:
        if interface['ifname'] == 'docker0' or interface['ifname'].startswith('br-'):
            addresses.extend(a['local'] for a in interface.get('addr_info', [])
                             if a['family'] == 'inet')
    addresses = sorted(set(addresses), key=ipaddress.ip_address)
    resolver = Path('/etc/resolv.conf').read_text()
    assert re.search(r'^nameserver 127\.0\.0\.53$', resolver, re.M)
    upstreams = run('resolvectl', 'dns').stdout
    assert '127.0.0.1' not in upstreams and '::1' not in upstreams
    smtp_check = runpy.run_path(str(Path(__file__).with_name('disable-legacy-php.py')))['smtp_check']
    smtp_check()
    edited = original.replace('listen-on-v6 { any; };',
        '// ARM private DNS listeners\n\tlisten-on { ' + '; '.join(addresses) +
        '; };\n\tlisten-on-v6 { ::1; };')
    stamp = datetime.now().strftime('%Y%m%dT%H%M%S')
    backup = Path('/root') / ('arm-bind-listeners-' + stamp)
    backup.mkdir(mode=0o700)
    shutil.copy2(target, backup/'named.conf.options')
    (backup/'allowed-ipv4.json').write_text(json.dumps(addresses))
    rollback = backup/'rollback.py'
    rollback.write_text('import shutil,subprocess\n' +
        'shutil.copy2(' + repr(str(backup/'named.conf.options')) + ',' + repr(str(target)) + ')\n' +
        "subprocess.run(['named-checkconf'],check=True)\n" +
        "subprocess.run(['rndc','reconfig'],check=True)\n")
    timer = 'arm-bind-listeners-rollback-' + stamp.lower()
    run('systemd-run', '--unit='+timer, '--on-active=5m', '/usr/bin/python3', str(rollback))
    try:
        # Preserve owner/mode of the existing configuration.
        target.write_text(edited)
        run('named-checkconf')
        run('rndc', 'reconfig')
        for attempt in range(10):
            listeners = [line for line in run('ss', '-lnutpH').stdout.splitlines()
                         if '"named"' in line and line.split()[4].endswith(':53')]
            endpoints = {line.split()[4] for line in listeners}
            allowed = {a+':53' for a in addresses} | {'[::1]:53'}
            if endpoints and endpoints <= allowed and '127.0.0.1:53' in endpoints:
                break
            time.sleep(0.5)
        else:
            raise RuntimeError('BIND listeners do not match allowed addresses')
        answer = run('dig', '+time=2', '+tries=1', '+noall', '+answer',
                     '@127.0.0.1', 'localhost', 'SOA').stdout
        assert 'SOA' in answer, 'Local authoritative DNS response failed'
        assert run('systemctl', 'is-active', 'named').stdout.strip() == 'active'
        smtp_check()
    except BaseException:
        run('/usr/bin/python3', str(rollback))
        run('systemctl', 'stop', timer+'.timer')
        raise
    (backup/'awaiting-client-confirmation').write_text(timer+'.timer\n')
    print('BIND_PRIVATE_LISTENERS_VERIFIED', flush=True)
    print('BACKUP='+str(backup), flush=True)
    print('ROLLBACK_TIMER='+timer+'.timer', flush=True)
    print('Verify external SSH, HTTP and container DNS before cancelling timer.', flush=True)


if __name__ == '__main__':
    main()
