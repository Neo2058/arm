#!/usr/bin/env python3
"""Enable only the reviewed SSH jail with NEW-only bans and timed rollback."""
import argparse
import ast
from datetime import datetime
import ipaddress
import os
from pathlib import Path
import re
import shutil
import subprocess
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--admin-ip", required=True)
    args = parser.parse_args()
    ipaddress.ip_address(args.admin_ip)
    if not args.apply or os.geteuid() != 0:
        raise SystemExit("Root and explicit --apply are required.")
    os.chdir('/home/deploy/arm')
    os.umask(0o077)
    active = subprocess.run(['systemctl', 'is-active', '--quiet', 'fail2ban']).returncode == 0
    if active:
        raise SystemExit('Fail2Ban is already active; inspect instead of reconfiguring blindly.')
    ssh = subprocess.check_output(['/usr/sbin/sshd', '-T'], text=True)
    assert 'passwordauthentication no\n' in ssh and 'port 22\n' in ssh
    targets = [Path('/etc/fail2ban/jail.d/99-arm-sshd.local'),
               Path('/etc/fail2ban/action.d/arm-sshd-new.conf'),
               Path('/etc/systemd/system/fail2ban.service.d/arm-resource-limits.conf')]
    if any(p.exists() for p in targets):
        raise SystemExit('Managed configuration already exists; inspect before retrying.')
    stamp = datetime.now().strftime('%Y%m%dT%H%M%S')
    backup = Path('/root') / ('arm-fail2ban-' + stamp)
    backup.mkdir(mode=0o700)
    shutil.copytree('/etc/fail2ban', backup/'fail2ban')
    for tool in ['iptables-save', 'ip6tables-save']:
        (backup/tool).write_bytes(subprocess.check_output([tool]))
    rollback = backup/'rollback.py'
    rollback.write_text("""import pathlib,subprocess
subprocess.run(['systemctl','stop','fail2ban'],check=True,timeout=45)
for p in FILES:pathlib.Path(p).unlink(missing_ok=True)
subprocess.run(['systemctl','daemon-reload'],check=True)
""".replace('FILES', repr([str(p) for p in targets])))
    unit = 'arm-fail2ban-rollback-' + stamp.lower()
    subprocess.run(['systemd-run', '--unit='+unit, '--on-active=5m',
                    '/usr/bin/python3', str(rollback)], check=True)
    try:
        targets[0].write_text(Path('ops/fail2ban/99-arm-sshd.local.template').read_text()
                              .replace('@ADMIN_IP@', args.admin_ip))
        targets[1].write_bytes(Path('ops/fail2ban/arm-sshd-new.conf').read_bytes())
        targets[2].parent.mkdir(parents=True, exist_ok=True)
        targets[2].write_text('[Service]\nMemoryHigh=384M\nMemoryMax=512M\n')
        for path in targets:
            path.chmod(0o644)
        subprocess.run(['fail2ban-client', '-t'], check=True, capture_output=True, timeout=30)
        merged = subprocess.check_output(['fail2ban-client', '-d'], text=True, timeout=30)
        (backup/'merged-config.txt').write_text(merged)
        commands = []
        for line in merged.splitlines():
            if line.startswith('['):
                commands.append(ast.literal_eval(line))
        assert {c[1] for c in commands if c[0]=='add'} == {'sshd'}
        assert '--ctstate NEW' in merged and '--syn' in merged and 'f2b-arm-sshd' in merged
        subprocess.run(['systemctl', 'daemon-reload'], check=True)
        subprocess.run(['systemctl', 'start', 'fail2ban'], check=True, timeout=45)
        for _ in range(30):
            ping = subprocess.run(['fail2ban-client', 'ping'], capture_output=True, timeout=3)
            if ping.returncode == 0:
                break
            time.sleep(0.5)
        else:
            raise RuntimeError('Fail2Ban socket did not become ready')
        subprocess.run(['fail2ban-client', 'set', 'sshd', 'unbanip', args.admin_ip],
                       check=True, capture_output=True, timeout=15)
        status = subprocess.check_output(['fail2ban-client', 'status'], text=True, timeout=15)
        assert re.search(r'Jail list:\s*sshd\s*$', status)
        with (backup/'network-test.log').open('w') as output:
            subprocess.run(['python3', 'scripts/verify-fail2ban-ssh.py'], stdout=output,
                           stderr=subprocess.STDOUT, check=True, timeout=120)
        subprocess.run(['systemctl', 'enable', 'fail2ban'], check=True, capture_output=True)
    except BaseException:
        subprocess.run(['/usr/bin/python3', str(rollback)], check=True)
        subprocess.run(['systemctl', 'stop', unit+'.timer'], check=True)
        (backup/'rolled-back').write_text('Original stopped state restored; inspect private logs.\n')
        raise
    (backup/'awaiting-client-confirmation').write_text(unit+'.timer\n')
    print('FAIL2BAN_SSH_IPV4_IPV6_CHECKS_PASSED', flush=True)
    print('ROLLBACK_TIMER='+unit+'.timer', flush=True)
    print('Backup:', backup, flush=True)
    print('Confirm a NEW external key-only SSH session before cancelling rollback.', flush=True)


if __name__ == '__main__':
    main()
