#!/usr/bin/env python3
"""Isolated SSH authentication change; never changes firewall or Fail2Ban.

An external, independently authenticated client MUST test the new connection and
cancel the rollback timer. Otherwise the new drop-in is removed after five minutes.
"""
import argparse
from datetime import datetime
import ipaddress
import os
from pathlib import Path
import shutil
import subprocess


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--client-ip", required=True)
    args = parser.parse_args()
    ipaddress.ip_address(args.client_ip)
    if not args.apply or os.geteuid() != 0:
        raise SystemExit("Requires root and explicit --apply after two key-only login checks.")
    target = Path("/etc/ssh/sshd_config.d/00-arm-keys-only.conf")
    if target.exists():
        raise SystemExit("Drop-in exists; inspect rather than overwrite.")
    os.umask(0o077)
    backup = Path("/root") / ("arm-ssh-keys-" + datetime.now().strftime("%Y%m%dT%H%M%S"))
    backup.mkdir(mode=0o700)
    shutil.copy2("/etc/ssh/sshd_config", backup / "sshd_config")
    shutil.copytree("/etc/ssh/sshd_config.d", backup / "sshd_config.d")
    rollback = backup / "rollback.py"
    rollback.write_text("""import pathlib, subprocess
pathlib.Path('/etc/ssh/sshd_config.d/00-arm-keys-only.conf').unlink(missing_ok=True)
subprocess.run(['/usr/sbin/sshd','-t'],check=True)
subprocess.run(['systemctl','reload','ssh'],check=True)
""")
    unit = "arm-ssh-keys-rollback-" + datetime.now().strftime("%Y%m%d%H%M%S")
    subprocess.run(["systemd-run", "--unit=" + unit, "--on-active=5m",
                    "/usr/bin/python3", str(rollback)], check=True)
    try:
        target.write_text("PasswordAuthentication no\nKbdInteractiveAuthentication no\n"
                          "PermitRootLogin prohibit-password\nPubkeyAuthentication yes\n")
        target.chmod(0o644)
        subprocess.run(["/usr/sbin/sshd", "-t"], check=True)
        for user in ["deploy", "root"]:
            output = subprocess.check_output(["/usr/sbin/sshd", "-T", "-C",
                      f"user={user},addr={args.client_ip},host=localhost"], text=True)
            settings = dict(line.split(" ", 1) for line in output.splitlines())
            assert settings["passwordauthentication"] == "no"
            assert settings["kbdinteractiveauthentication"] == "no"
            assert settings["pubkeyauthentication"] == "yes"
            assert settings["permitrootlogin"] in {"prohibit-password", "without-password"}
        subprocess.run(["systemctl", "reload", "ssh"], check=True)
    except BaseException:
        subprocess.run(["/usr/bin/python3", str(rollback)], check=True)
        subprocess.run(["systemctl", "stop", unit + ".timer"], check=True)
        raise
    print("SSH_KEYS_ONLY_RELOADED", flush=True)
    print("ROLLBACK_TIMER=" + unit + ".timer", flush=True)
    print("Test independent key login before cancelling the timer. Backup:", backup, flush=True)


if __name__ == "__main__":
    main()
