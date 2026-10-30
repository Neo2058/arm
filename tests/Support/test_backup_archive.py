"""Security checks for accepting recovery archives; all data/keys are synthetic."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import base64
import contextlib
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock


spec = importlib.util.spec_from_file_location('pull_full_backup',
    Path(__file__).resolve().parents[2]/'scripts'/'pull-full-backup.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


@unittest.skipUnless(shutil.which('age') and shutil.which('age-keygen'), 'age tools required')
class BackupArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.key = self.base/'identity'
        subprocess.run(['age-keygen', '-o', str(self.key)], check=True, capture_output=True)
        self.recipient = subprocess.check_output(['age-keygen', '-y', str(self.key)]).decode().strip()

    def archive(self, *, bad_hash=False, unsafe=False, duplicate=False, omit=None):
        files = {name: b'synthetic' for name in [
            'postgres.dump', 'postgres-roles.sql', 'objects.json', 'configuration.tar',
            'source.tar', 'public.tar', 'containers.json']}
        files['postgres.dump'] = b'PGDMP-synthetic'
        files['postgres-restore-report.json'] = json.dumps({'result': 'PASS', 'restores': [{
            'sha256': hashlib.sha256(files['postgres.dump']).hexdigest()}]}).encode()
        if omit:
            del files[omit]
        manifest = {'format': 'arm-recovery-set-v1', 'commit': 'test', 'objects': 0,
            'storage_files': 0, 'analytics_tables': [], 'consistency': 'test', 'files': {
                k: {'bytes': len(v), 'sha256': hashlib.sha256(v).hexdigest()} for k, v in files.items()}}
        if bad_hash:
            manifest['files']['postgres.dump']['sha256'] = '0'*64
        files['manifest.json'] = json.dumps(manifest).encode()
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w:gz') as archive:
            for name, data in files.items():
                info = tarfile.TarInfo('arm-backup/'+name)
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
            if unsafe or duplicate:
                info = tarfile.TarInfo('arm-backup/../../outside' if unsafe else 'arm-backup/source.tar')
                info.size = 1
                archive.addfile(info, io.BytesIO(b'x'))
        encrypted = subprocess.check_output(['age', '-r', self.recipient], input=buffer.getvalue())
        path = self.base/'archive.age'
        path.write_bytes(encrypted)
        return path

    def test_valid_set_requires_decryption_and_every_member_hash(self):
        result = backup.verify_archive(self.archive(), self.key)
        self.assertEqual(result['files'], 8)
        self.assertEqual(result['postgres_restore_test'], 'PASS')

    def test_changed_manifest_hash_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'manifest hashes'):
            backup.verify_archive(self.archive(bad_hash=True), self.key)

    def test_missing_required_component_is_rejected_even_with_valid_manifest(self):
        with self.assertRaisesRegex(ValueError, 'components missing'):
            backup.verify_archive(self.archive(omit='configuration.tar'), self.key)

    def test_traversal_is_rejected_without_extracting(self):
        with self.assertRaisesRegex(ValueError, 'Unsafe archive'):
            backup.verify_archive(self.archive(unsafe=True), self.key)
        self.assertFalse((self.base/'outside').exists())

    def test_duplicate_member_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Duplicate archive'):
            backup.verify_archive(self.archive(duplicate=True), self.key)

    def test_damaged_ciphertext_is_rejected(self):
        path = self.archive()
        data = bytearray(path.read_bytes())
        data[-1] ^= 1
        path.write_bytes(data)
        with self.assertRaises((ValueError, tarfile.TarError, EOFError, OSError)):
            backup.verify_archive(path, self.key)

    def test_absolute_and_foreign_paths_are_rejected(self):
        for name in ['/etc/passwd', 'elsewhere/file', 'arm-backup/../secret']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                backup.safe_member(name)

    def pull_partial(self, corrupt_prefix=False, streams=1):
        encrypted = self.archive().read_bytes()
        destination = self.base/'destination'
        destination.mkdir(mode=0o700)
        name = 'arm-full-20261030T162223Z.tar.gz.age'
        partial = destination/(name+'.partial')
        offset = len(encrypted)//2
        prefix = bytearray(encrypted[:offset])
        if corrupt_prefix:
            prefix[0] ^= 1
        partial.write_bytes(prefix)
        partial.chmod(0o600)
        expected = hashlib.sha256(encrypted).hexdigest()
        real_popen = subprocess.Popen
        offsets = []

        def spawn(command, **kwargs):
            if command[0] == 'ssh':
                match = re.search(r'f.seek\((\d+)\)', command[-1])
                start = int(match.group(1))
                offsets.append(start)
                length_match = re.search(r'remaining=(\d+)', command[-1])
                length = int(length_match.group(1)) if length_match else len(encrypted)-start
                tail = base64.b64encode(encrypted[start:start+length]).decode()
                program = ("import sys,base64;sys.stdin.readline();"
                           "sys.stdout.buffer.write(base64.b64decode('"+tail+"'))")
                return real_popen([sys.executable, '-c', program], **kwargs)
            return real_popen(command, **kwargs)

        args = ['pull-full-backup.py', '--source', '/var/lib/arm-backups/'+name,
                '--sha256', expected, '--bytes', str(len(encrypted)),
                '--directory', str(destination), '--age-identity', str(self.key), '--streams', str(streams)]
        with mock.patch.object(sys, 'argv', args), \
             mock.patch.object(backup.getpass, 'getpass', return_value='synthetic-not-a-secret'), \
             mock.patch.object(backup.subprocess, 'Popen', side_effect=spawn), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            if corrupt_prefix:
                with self.assertRaisesRegex(ValueError, 'Ciphertext checksum mismatch'):
                    backup.main()
                self.assertFalse((destination/name).exists())
                self.assertTrue(partial.exists())
            else:
                backup.main()
                self.assertEqual((destination/name).read_bytes(), encrypted)
                self.assertFalse(partial.exists())
                receipt = json.loads((destination/(name+'.verified.json')).read_text())
                self.assertEqual(receipt['sha256'], expected)
        if streams == 1:
            self.assertEqual(offsets, [offset])
        else:
            self.assertEqual(len(offsets), streams)
            self.assertEqual(min(offsets), offset)

    def test_resumed_pull_checks_complete_ciphertext_and_decrypts(self):
        self.pull_partial()

    def test_corrupt_partial_is_never_promoted_to_completed_copy(self):
        self.pull_partial(corrupt_prefix=True)

    def test_parallel_ranges_resume_prefix_and_validate_final_archive(self):
        self.pull_partial(streams=4)


if __name__ == '__main__':
    unittest.main()
