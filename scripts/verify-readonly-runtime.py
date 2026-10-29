#!/usr/bin/env python3
"""Operational smoke checks; no user mutations, messages, or analytics events."""
import argparse
import json
import subprocess


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--container", default="arm-readonly-candidate")
    parser.add_argument("--url", default="http://arm-readonly-candidate-web:8080")
    args = parser.parse_args()

    def run(command, user=None):
        base = ["docker", "exec"] + (["-u", user] if user else [])
        result = subprocess.run(base + [args.container, *command], capture_output=True, text=True)
        if result.returncode:
            # Do not print raw application errors: they may include credentials.
            raise RuntimeError("Container smoke command failed: " + command[0])
        return result.stdout.strip()

    info = json.loads(subprocess.check_output(["docker", "inspect", args.container]))[0]
    assert info["HostConfig"]["ReadonlyRootfs"], "Root filesystem is writable"
    assert not next(m for m in info["Mounts"] if m["Destination"] == "/var/www")["RW"]
    assert "no-new-privileges:true" in info["HostConfig"]["SecurityOpt"]
    for path, expected in [("/login", 200), ("/install", 200), ("/worktime", 302),
                           ("/api/mobile/documents", 401), ("/admin/login", 200)]:
        actual = run(["curl", "-sS", "-o", "/dev/null", "--max-time", "15",
                      "-H", "Host: www.xn---15-7edz.xn--p1ai", "-H",
                      "Accept: application/json" if path.startswith("/api/") else "Accept: text/html",
                      "-w", "%{http_code}", args.url.rstrip("/") + path])
        assert actual == str(expected), (path, actual)
        print(path, actual, flush=True)

    php = r'''
require "/var/www/vendor/autoload.php";
$a=require "/var/www/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=Illuminate\Support\Facades\DB::selectOne("SELECT current_user,rolsuper FROM pg_roles WHERE rolname=current_user");
if($r->rolsuper)exit(2);
$d=App\Models\Document::findOrFail(17);
$s=Illuminate\Support\Facades\Storage::disk("s3")->readStream($d->file_path);
if(!is_resource($s)||fread($s,5)!=="%PDF-")exit(3);
fclose($s);
$c=new ClickHouseDB\Client(config("clickhouse"));
$c->select("SELECT 1")->rows();
$c->write("INSERT INTO user_actions SELECT * FROM user_actions WHERE 0");
Illuminate\Support\Facades\Queue::connection("database")->size("notifications");
echo "DB_S3_ANALYTICS_QUEUE_OK";
'''
    print(run(["php", "-r", php], "www-data"), flush=True)
    for user, paths in [("root", ["/var/www/app", "/usr/local/lib"]),
                        ("www-data", ["/var/www/app", "/var/www/bootstrap/cache"])]:
        for path in paths:
            program = ('$p=' + json.dumps(path) + '."/.arm-security-probe-".bin2hex(random_bytes(8));'
                       '$r=@file_put_contents($p,"probe");if($r!==false){unlink($p);exit(1);}')
            run(["php", "-r", program], user)
            print(user, path, "write denied", flush=True)
    run(["php", "-r", '$p=tempnam("/tmp","arm-probe-");if(!$p)exit(1);unlink($p);'
         '$p="/var/www/storage/logs/.arm-security-probe-".bin2hex(random_bytes(8));'
         'if(file_put_contents($p,"probe")===false)exit(2);unlink($p);'], "www-data")
    print(run(["python3", "-c", 'import pdfplumber; print("PDF_PYTHON_OK")'], "www-data"))
    run(["sh", "-c", 'probe_file=$(mktemp); trap \'rm -f "$probe_file"\' EXIT; '
         'printf "ARM harmless scanner probe" > "$probe_file"; '
         'clamdscan --config-file=/var/www/docker/clamav/clamd-client.conf --stream --no-summary "$probe_file"'],
        "www-data")
    print("SCANNER_AND_RUNTIME_WRITES_OK", flush=True)
    print("READONLY_RUNTIME_CHECKS_PASSED", flush=True)


if __name__ == "__main__":
    main()
