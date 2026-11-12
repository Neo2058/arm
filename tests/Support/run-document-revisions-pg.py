"""Run isolated document migration/race checks; requires local PostgreSQL CLI and PHP pcntl.

Run from the repository root. Creates a private disposable cluster with no TCP
listener, uses synthetic records, stops it and removes its own temporary directory.
"""
import getpass, os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(tempfile.mkdtemp(prefix='arm-document-pg.', dir='/private/tmp'))
socket = root / 'socket'
socket.mkdir(mode=0o700)
started = False
try:
    subprocess.run(['initdb', '-D', str(root/'data'), '-A', 'trust', '-U', getpass.getuser(), '--no-instructions'], check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['pg_ctl', '-D', str(root/'data'), '-l', str(root/'postgres.log'), '-o', f"-k {socket} -p 65433 -c listen_addresses='' -c max_connections=20 -c shared_buffers=32MB", '-w', 'start'], check=True, stdout=subprocess.DEVNULL)
    started = True
    subprocess.run(['createdb', '-h', str(socket), '-p', '65433', '-U', getpass.getuser(), 'arm_document_test_revisions'], check=True)
    env = dict(os.environ, APP_ENV='testing', DB_CONNECTION='pgsql', DB_URL='', DB_HOST=str(socket), DB_PORT='65433', DB_DATABASE='arm_document_test_revisions', DB_USERNAME=getpass.getuser(), DB_PASSWORD='', SESSION_DRIVER='array', CACHE_STORE='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array', BCRYPT_ROUNDS='4')
    result = subprocess.run(['php', 'tests/Support/document-revisions-concurrency.php'], env=env, timeout=120, capture_output=True, text=True)
    print(result.stdout)
    if result.returncode or 'POSTGRES_DOCUMENT_MIGRATIONS_AND_RACES_PASS' not in result.stdout:
        print(result.stderr)
        raise SystemExit(1)
finally:
    if started:
        subprocess.run(['pg_ctl', '-D', str(root/'data'), '-m', 'fast', '-w', 'stop'], check=True, stdout=subprocess.DEVNULL)
    shutil.rmtree(root)
