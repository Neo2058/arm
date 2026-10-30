#!/usr/bin/env python3
"""Disable audited, unused ProFTPD service/socket activation with timed rollback.

Keeps packages/configuration and SSH SFTP intact. Requires root and external
SSH/SFTP/HTTP verification before cancelling the printed rollback timer.
"""
import argparse
from datetime import datetime
import json
import os
from pathlib import Path
import shutil
import subprocess


UNITS = ['proftpd.service', 'proftpd.socket', 'proftpd@.service']


def run(*args, check=True):
    return subprocess.run(args, capture_output=True, text=True, check=check, timeout=45)


def state(unit):
    return {key: run('systemctl', command, unit, check=False).stdout.strip()
            for key, command in [('enabled', 'is-enabled'), ('active', 'is-active')]}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if not args.apply or os.geteuid() != 0:
        raise SystemExit('Requires root and explicit --apply after FTP usage approval.')
    os.umask(0o077)
    before = {unit: state(unit) for unit in UNITS}
    assert before['proftpd.service'] == {'enabled': 'enabled', 'active': 'active'}
    assert before['proftpd.socket'] == {'enabled': 'disabled', 'active': 'inactive'}
    assert before['proftpd@.service']['enabled'] == 'static'
    assert not run('ss', '-tnH', 'state', 'established', 'sport', '=', ':21').stdout.strip()
    instances = run('systemctl', 'list-units', '--all', '--no-legend',
                    'proftpd@*.service').stdout.strip()
    assert not instances, 'Inspect existing FTP instances before proceeding'
    protected = ['ssh', 'fail2ban', 'named', 'exim4', 'dovecot']
    protected_before = {s: state(s)['active'] for s in protected}
    stamp = datetime.now().strftime('%Y%m%dT%H%M%S')
    backup = Path('/root') / ('arm-ftp-' + stamp)
    backup.mkdir(mode=0o700)
    shutil.copytree('/etc/proftpd', backup/'proftpd')
    (backup/'unit-states.json').write_text(json.dumps(before))
    for unit in UNITS:
        (backup/(unit+'.txt')).write_text(run('systemctl', 'cat', unit).stdout)
    rollback = backup/'rollback.py'
    rollback.write_text('import subprocess\n' +
        "subprocess.run(['systemctl','unmask'," + ','.join(repr(u) for u in UNITS) +
        "],check=True)\n" +
        "subprocess.run(['systemctl','enable','--now','proftpd.service'],check=True)\n")
    timer = 'arm-ftp-rollback-' + stamp.lower()
    run('systemd-run', '--unit='+timer, '--on-active=5m', '/usr/bin/python3', str(rollback))
    try:
        run('systemctl', 'disable', '--now', 'proftpd.socket', 'proftpd.service')
        run('systemctl', 'mask', *UNITS)
        for unit in UNITS:
            assert state(unit)['enabled'] == 'masked'
        assert state('proftpd.service')['active'] == 'inactive'
        assert state('proftpd.socket')['active'] == 'inactive'
        assert run('pgrep', '-x', 'proftpd', check=False).returncode == 1
        listeners = run('ss', '-lntupH').stdout
        for line in listeners.splitlines():
            port = line.split()[4].rsplit(':', 1)[-1]
            if port.isdigit():
                assert int(port) not in {21, 69, 990} and not 35000 <= int(port) <= 35999, \
                    'FTP/TFTP/passive-range listener remains; inspect separately'
        for service, active in protected_before.items():
            assert state(service)['active'] == active
    except BaseException:
        run('/usr/bin/python3', str(rollback))
        run('systemctl', 'stop', timer+'.timer')
        raise
    (backup/'awaiting-client-confirmation').write_text(timer+'.timer\n')
    print('FTP_STOPPED_AND_MASKED_NO_LISTENERS', flush=True)
    print('BACKUP='+str(backup), flush=True)
    print('ROLLBACK_TIMER='+timer+'.timer', flush=True)
    print('Verify NEW SSH, SFTP and HTTP before cancelling timer.', flush=True)


if __name__ == '__main__':
    main()
