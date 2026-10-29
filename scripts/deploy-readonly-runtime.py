#!/usr/bin/env python3
"""Apply the reviewed read-only overlay, with automatic configuration rollback."""
import argparse
from datetime import datetime
import json
import os
from pathlib import Path
import re
import subprocess


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if not args.apply or os.geteuid() != 0:
        raise SystemExit("Requires root and explicit --apply after candidate checks.")
    os.umask(0o077)
    root = Path("/home/deploy/arm")
    os.chdir(root)
    os.environ.pop("COMPOSE_FILE", None)
    os.environ["COMPOSE_ANSI"] = "never"
    env_path = root / ".env"
    original = env_path.read_bytes()
    ownership = env_path.stat()
    text = original.decode()
    match = re.search(r"^COMPOSE_FILE=(.*)$", text, re.M)
    if not match:
        raise SystemExit("Existing COMPOSE_FILE must be explicit; refusing to guess.")
    files = match.group(1).strip().strip("\"'").split(":")
    if "docker-compose.security.yml" not in files:
        raise SystemExit("Security overlay is required.")
    if "docker-compose.readonly.yml" in files:
        raise SystemExit("Read-only overlay already selected; inspect rather than reapply.")
    backup = Path("/root") / ("arm-readonly-" + datetime.now().strftime("%Y%m%dT%H%M%S"))
    backup.mkdir(mode=0o700)
    (backup / "before.env").write_bytes(original)
    (backup / "container-before.json").write_bytes(subprocess.check_output(["docker", "inspect", "arm-app"]))

    def write_env(content):
        env_path.write_bytes(content)
        os.chown(env_path, ownership.st_uid, ownership.st_gid)
        env_path.chmod(0o600)

    def command(cmd, log, timeout=120):
        with (backup / log).open("w") as output:
            subprocess.run(cmd, stdout=output, stderr=subprocess.STDOUT, check=True, timeout=timeout)

    # The candidate has isolated storage and must pass immediately before cutover.
    command(["python3", "scripts/verify-readonly-runtime.py"], "candidate-check.log")
    selected = ":".join([*files, "docker-compose.readonly.yml"])
    new_text = text[:match.start(1)] + selected + text[match.end(1):]
    try:
        write_env(new_text.encode())
        configuration = json.loads(subprocess.check_output(["docker", "compose", "config", "--format", "json"]))
        app = configuration["services"]["app"]
        assert app["read_only"]
        assert next(v for v in app["volumes"] if v["target"] == "/var/www")["read_only"]
        assert app["entrypoint"] == ["/bin/sh", "/var/www/docker/readonly-entrypoint.sh"]
        command(["docker", "exec", "arm-nginx", "nginx", "-t"], "nginx-preflight.log")
        command(["docker", "compose", "up", "-d", "--no-deps", "--wait", "--wait-timeout", "60", "app"], "cutover.log")
        command(["docker", "exec", "arm-nginx", "nginx", "-s", "reload"], "nginx-reload.log")
        command(["python3", "scripts/verify-readonly-runtime.py", "--container", "arm-app",
                 "--url", "https://www.xn---15-7edz.xn--p1ai"], "production-check.log")
    except BaseException:
        write_env(original)
        command(["docker", "compose", "up", "-d", "--no-deps", "--wait", "--wait-timeout", "60", "app"], "rollback.log")
        command(["docker", "exec", "arm-nginx", "nginx", "-s", "reload"], "rollback-nginx.log")
        (backup / "rolled-back").write_text("Check private logs; original configuration restored.\n")
        raise
    (backup / "success").write_text("Candidate and production operational checks passed.\n")
    print("READONLY_DEPLOYMENT_VERIFIED", str(backup), flush=True)


if __name__ == "__main__":
    main()
