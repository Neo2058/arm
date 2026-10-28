#!/usr/bin/env python3
"""Run reviewed migration commands with host-only DB credentials over stdin.

Requires the provisioned security overlay. Never prints or passes DB passwords
in process arguments. Runtime PHP keeps its unprivileged database connection.
"""
import base64
import json
import os
from pathlib import Path
import subprocess
import sys


def main():
    if os.geteuid() != 0:
        raise SystemExit("Run as root via sudo; administrative credentials are host-only.")
    args = sys.argv[1:] or ["migrate:status"]
    if args[0] not in {"migrate:status", "migrate"}:
        raise SystemExit("Only migrate:status and migrate are supported.")
    if any("\x00" in arg for arg in args):
        raise SystemExit("Invalid argument.")
    source = Path("/etc/arm/services/postgres.env")
    info = source.stat()
    if info.st_uid != 0 or info.st_mode & 0o077:
        raise SystemExit("postgres.env must be owned by root with mode 0600.")
    env = dict(line.split("=", 1) for line in source.read_text().splitlines()
               if line and not line.startswith("#") and "=" in line)
    credentials = base64.b64encode(json.dumps({
        "user": env["POSTGRES_USER"], "password": env["POSTGRES_PASSWORD"],
        "database": env["POSTGRES_DB"],
    }).encode()).decode()
    program = """<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
$credentials = json_decode(base64_decode('CREDENTIALS'), true);
config(['database.connections.pgsql.username' => $credentials['user'],
        'database.connections.pgsql.password' => $credentials['password'],
        'database.connections.pgsql.database' => $credentials['database']]);
Illuminate\\Support\\Facades\\DB::purge('pgsql');
unset($credentials);
$input = new Symfony\\Component\\Console\\Input\\ArgvInput($argv);
$status = $kernel->handle($input, new Symfony\\Component\\Console\\Output\\ConsoleOutput());
$kernel->terminate($input, $status);
exit($status);
""".replace("CREDENTIALS", credentials)
    # php /dev/stdin defines normal CLI streams/argv; a bare stdin script does not.
    result = subprocess.run(["docker", "exec", "-i", "-u", "root", "arm-app",
                             "php", "/dev/stdin", *args], input=program.encode())
    return result.returncode


if __name__ == "__main__":
    sys.exit(main())
