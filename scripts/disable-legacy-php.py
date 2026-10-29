#!/usr/bin/env python3
"""Stop only the audited Roundcube PHP 7.4 service, with timed rollback.

No mail is sent. Mail services, SSH, firewall and application containers stay intact.
An independent client must verify production and cancel the printed rollback timer.
"""
import argparse
from datetime import datetime
import ipaddress
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess


def run(*args, check=True):
    return subprocess.run(args, check=check, capture_output=True, text=True, timeout=45)


MAIL_CONFIG = r"""
$c=require '/var/www/bootstrap/cache/config.php';
$m=$c['mail']['mailers']['smtp'];
if(!empty($m['url'])) {
    $u=parse_url($m['url']);
    if(!$u || empty($u['host'])) exit(2);
    $m['host']=$u['host']; $m['port']=$u['port']??$m['port'];
}
"""


def smtp_check():
    php = MAIL_CONFIG + r"""
if($c['mail']['default']!=='smtp' || (int)$m['port']!==465) exit(2);
$context=stream_context_create(['ssl'=>[
    'verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,
    'peer_name'=>$m['host']]]);
$s=@stream_socket_client('ssl://'.$m['host'].':465',$errno,$error,10,
    STREAM_CLIENT_CONNECT,$context);
if(!$s) exit(3);
stream_set_timeout($s,10); $banner=fgets($s,512); fclose($s);
if(!is_string($banner) || !str_starts_with($banner,'220')) exit(4);
echo 'SMTP_TLS_BANNER_OK_NO_MAIL_SENT';
"""
    result = run('docker', 'exec', 'arm-app', 'php', '-r', php)
    assert result.stdout == 'SMTP_TLS_BANNER_OK_NO_MAIL_SENT'
    print(result.stdout, flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if not args.apply or os.geteuid() != 0:
        raise SystemExit('Requires root and explicit --apply after dependency audit.')
    os.umask(0o077)
    unit = 'php-fpm74.service'
    assert run('systemctl', 'is-enabled', unit).stdout.strip() == 'enabled'
    assert run('systemctl', 'is-active', unit).stdout.strip() == 'active'
    for web in ['nginx', 'apache2', 'httpd']:
        assert run('systemctl', 'is-active', '--quiet', web, check=False).returncode != 0
    config = Path('/opt/php74/etc/php-fpm.d/roundcube.php-fpm.conf')
    assert 'listen = /var/run/php-fpm74.roundcube.sock' in config.read_text()
    assert 'roundcube' not in run('ss', '-xnpH', 'state', 'established').stdout
    php = MAIL_CONFIG + "echo json_encode(['host'=>$m['host'],'port'=>$m['port']]);"
    smtp = json.loads(run('docker', 'exec', 'arm-app', 'php', '-r', php).stdout)
    own = {a['local'] for i in json.loads(run('ip', '-j', 'address').stdout)
           for a in i.get('addr_info', [])}
    addresses = {i[4][0] for i in socket.getaddrinfo(smtp['host'], None)}
    assert addresses and all(a not in own and not ipaddress.ip_address(a).is_loopback
                             for a in addresses), 'SMTP must not point at this host'
    smtp_check()
    services = ['exim4', 'dovecot', 'php8.3-fpm', 'php8.4-fpm', 'fail2ban', 'ssh']
    before = {s: run('systemctl', 'is-active', s, check=False).stdout.strip() for s in services}
    stamp = datetime.now().strftime('%Y%m%dT%H%M%S')
    backup = Path('/root') / ('arm-legacy-php-' + stamp)
    backup.mkdir(mode=0o700)
    shutil.copytree('/opt/php74/etc', backup / 'php74-etc')
    (backup / 'unit.txt').write_text(run('systemctl', 'cat', unit).stdout)
    (backup / 'services-before.json').write_text(json.dumps(before))
    rollback = backup / 'rollback.py'
    rollback.write_text("""import subprocess
subprocess.run(['systemctl','unmask','php-fpm74.service'],check=True)
subprocess.run(['systemctl','enable','--now','php-fpm74.service'],check=True)
""")
    timer = 'arm-legacy-php-rollback-' + stamp.lower()
    run('systemd-run', '--unit='+timer, '--on-active=5m', '/usr/bin/python3', str(rollback))
    try:
        run('systemctl', 'disable', '--now', unit)
        run('systemctl', 'mask', unit)
        assert run('systemctl', 'is-enabled', unit, check=False).stdout.strip() == 'masked'
        assert run('systemctl', 'is-active', '--quiet', unit, check=False).returncode != 0
        assert 'php-fpm74.roundcube.sock' not in run('ss', '-lxnH').stdout
        for service, state in before.items():
            assert run('systemctl', 'is-active', service, check=False).stdout.strip() == state
        smtp_check()
    except BaseException:
        run('/usr/bin/python3', str(rollback))
        run('systemctl', 'stop', timer+'.timer')
        raise
    (backup / 'awaiting-client-confirmation').write_text(timer+'.timer\n')
    print('LEGACY_PHP_STOPPED_AND_MASKED', flush=True)
    print('BACKUP='+str(backup), flush=True)
    print('ROLLBACK_TIMER='+timer+'.timer', flush=True)
    print('Verify NEW external SSH, HTTP, and container health before cancelling timer.', flush=True)


if __name__ == '__main__':
    main()
