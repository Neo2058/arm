#!/usr/bin/env python3
"""Test NEW-connection bans and established-session survival on isolated IPs.

Requires root, ip netns, ssh-keyscan, and the configured sshd jail. Only RFC 5737 /
RFC 3849 test addresses are banned. Does not authenticate or change authorized_keys.
"""
import os
import argparse
import ipaddress
import json
import select
import subprocess
import sys
import tempfile


PROXY = r'''
import socket, subprocess, select, sys, time
listener=socket.socket();listener.bind(('127.0.0.1',0));listener.listen(1);listener.settimeout(10)
scan=subprocess.Popen(['ssh-keyscan','-4','-T','30','-p',str(listener.getsockname()[1]),'-t','ed25519','127.0.0.1'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
client,_=listener.accept();upstream=socket.create_connection((sys.argv[1],22),3)
upstream.settimeout(5);client.settimeout(5)
banner=upstream.recv(65536)
assert banner.startswith(b'SSH-'), 'Server did not send SSH identification'
client.sendall(banner)
identification=client.recv(65536)
assert identification.startswith(b'SSH-'), 'Client did not send SSH identification'
upstream.sendall(identification)
upstream.settimeout(None);client.settimeout(None)
print('ESTABLISHED',flush=True)
if sys.stdin.readline().strip()!='continue':raise SystemExit(1)
deadline=time.monotonic()+10
while time.monotonic()<deadline:
 ready,_,_=select.select([client,upstream],[],[],0.5)
 ended=False
 for source in ready:
  try:chunk=source.recv(65536)
  except ConnectionResetError:ended=True;break
  if not chunk:ended=True;break
  try:(upstream if source is client else client).sendall(chunk)
  except (ConnectionResetError,BrokenPipeError):ended=True;break
 if ended:break
client.close();upstream.close();listener.close()
out,err=scan.communicate(timeout=3)
assert scan.returncode==0 and b'ssh-ed25519' in out, (scan.returncode, err.decode(errors='replace'))
print('ESTABLISHED_SESSION_SURVIVED',flush=True)
'''


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--baseline-only', action='store_true',
                        help='Test SSH exchange without Fail2Ban or any bans')
    baseline = parser.parse_args().baseline_only
    if os.geteuid() != 0:
        raise SystemExit("Run as root.")
    ns = "arm-f2b-test"
    host_link = "armf2bhost"
    peer_link = "armf2bpeer"
    pairs = [("192.0.2.1", "192.0.2.123", "24"),
             ("2001:db8:ffff:1::1", "2001:db8:ffff:1::123", "64")]

    def run(*args, check=True):
        return subprocess.run(list(args), capture_output=True, text=True, check=check, timeout=20)

    if run("ip", "netns", "list").stdout.find(ns) >= 0:
        raise SystemExit("Test namespace exists; inspect instead of overwriting.")
    if run("ip", "link", "show", host_link, check=False).returncode == 0:
        raise SystemExit("Test interface exists; inspect instead of overwriting.")
    for family, network in [("-4", "192.0.2.0/24"), ("-6", "2001:db8:ffff:1::/64")]:
        routes = json.loads(run("ip", family, "-j", "route", "show", "table", "main").stdout)
        for route in routes:
            if route.get("dst", "default") != "default":
                assert not ipaddress.ip_network(route["dst"]).overlaps(ipaddress.ip_network(network)), \
                    "Documentation test subnet already routed; refusing to interfere"
    proxy = None
    with tempfile.NamedTemporaryFile(mode="w", prefix="arm-f2b-proxy-", suffix=".py") as helper:
        helper.write(PROXY)
        helper.flush()
        try:
            run("ip", "netns", "add", ns)
            run("ip", "link", "add", host_link, "type", "veth", "peer", "name", peer_link)
            run("ip", "link", "set", peer_link, "netns", ns)
            run("ip", "-n", ns, "link", "set", "lo", "up")
            for host, peer, mask in pairs:
                options = ["nodad"] if ":" in host else []
                run("ip", "addr", "add", host + "/" + mask, "dev", host_link, *options)
                run("ip", "-n", ns, "addr", "add", peer + "/" + mask, "dev", peer_link, *options)
            run("ip", "link", "set", host_link, "up")
            run("ip", "-n", ns, "link", "set", peer_link, "up")

            def can_connect(address):
                code = ("import socket,sys; s=socket.socket(socket.AF_INET6 if ':' in sys.argv[1] "
                        "else socket.AF_INET);s.settimeout(2);s.connect((sys.argv[1],22));s.close()")
                return run("ip", "netns", "exec", ns, "python3", "-c", code, address,
                           check=False).returncode == 0

            for host, peer, _ in pairs:
                if not baseline:
                    run("fail2ban-client", "set", "sshd", "unbanip", peer)
                assert can_connect(host), "Baseline SSH test failed"
                proxy = subprocess.Popen(["ip", "netns", "exec", ns, "python3", "-u", helper.name, host],
                                         stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                         stderr=subprocess.PIPE, text=True)
                ready, _, _ = select.select([proxy.stdout], [], [], 10)
                assert ready and proxy.stdout.readline().strip() == "ESTABLISHED"
                if not baseline:
                    run("fail2ban-client", "set", "sshd", "banip", peer)
                    tool = "ip6tables" if ":" in peer else "iptables"
                    rules = run(tool, "-S", "INPUT").stdout
                    jumps = [line for line in rules.splitlines() if "-j f2b-arm-sshd" in line]
                    assert jumps and all("--ctstate NEW" in line and "22" in line and
                                         "--tcp-flags FIN,SYN,RST,ACK SYN" in line for line in jumps)
                    assert not can_connect(host), "A banned NEW connection was allowed"
                output, errors = proxy.communicate("continue\n", timeout=15)
                assert proxy.returncode == 0 and "ESTABLISHED_SESSION_SURVIVED" in output, \
                    ("Established-session test failed", host, proxy.returncode, output, errors)
                proxy = None
                if not baseline:
                    run("fail2ban-client", "set", "sshd", "unbanip", peer)
                assert can_connect(host), "Unban did not restore new connections"
                print(("IPv6" if ":" in peer else "IPv4") +
                      (": baseline SSH exchange passed" if baseline else
                       ": NEW banned; existing SSH key exchange survived; unban verified"), flush=True)
        finally:
            if proxy is not None:
                proxy.kill()
                proxy.communicate()
            if not baseline:
                for _, peer, _ in pairs:
                    run("fail2ban-client", "set", "sshd", "unbanip", peer, check=False)
            run("ip", "netns", "delete", ns, check=False)
            run("ip", "link", "delete", host_link, check=False)
    print("SSH_BASELINE_PASSED" if baseline else "FAIL2BAN_IPV4_IPV6_SAFETY_CHECKS_PASSED", flush=True)


if __name__ == "__main__":
    main()
