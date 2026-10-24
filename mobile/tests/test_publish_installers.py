import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("publisher", Path(__file__).resolve().parents[1] / "scripts/publish_installers.py")
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class PublisherTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.root = Path(self.workspace.name)
        self.destination = self.root / "public/downloads"
        self.destination.mkdir(parents=True)
        self.source = self.root / "new.apk"
        self.source.write_bytes(b"new")
        self.target = self.destination / "tch15-android.apk"
        self.target.write_bytes(b"old")

    def tearDown(self):
        self.workspace.cleanup()

    def metadata(self, path):
        return {"package": publisher.PACKAGE, "version_code": 2 if path.read_bytes() == b"new" else 1,
                "sha256": publisher.digest(path), "version": "test", "certificate_sha256": publisher.CERTIFICATE}

    def test_wrong_certificate_is_rejected(self):
        with patch.object(publisher, "tool", return_value="apksigner"), patch.object(publisher, "run", return_value="Signer #1 certificate SHA-256 digest: " + "a" * 64):
            with self.assertRaisesRegex(ValueError, "certificate"):
                publisher.inspect_apk(self.source)

    def test_debug_and_test_only_apks_are_rejected(self):
        for flag in ["debuggable", "testOnly"]:
            with self.subTest(flag=flag), patch.object(publisher, "tool", return_value="tool"), patch.object(publisher, "run", side_effect=[
                "Number of signers: 1\nSigner #1 certificate SHA-256 digest: " + publisher.CERTIFICATE,
                "package: name='ru.tch15.arm' versionCode='5' versionName='1.0.4'",
                "A: android:" + flag + "(0x1)=(type 0x12)0xffffffff",
            ]):
                with self.assertRaisesRegex(ValueError, "Debug/test"):
                    publisher.inspect_apk(self.source)

    def test_build_tools_37_signer_format_is_supported(self):
        with patch.object(publisher, "tool", return_value="tool"), patch.object(publisher, "run", side_effect=[
            "Number of signers: 1\nV2 Signer: certificate SHA-256 digest: " + publisher.CERTIFICATE,
            "package: name='ru.tch15.arm' versionCode='5' versionName='1.0.4'", "manifest",
        ]):
            self.assertEqual(publisher.inspect_apk(self.source)["version_code"], 5)

    def test_multiple_signers_and_wrong_package_are_rejected(self):
        signature = "Number of signers: 2\nSigner #1 certificate SHA-256 digest: " + publisher.CERTIFICATE
        with patch.object(publisher, "tool", return_value="tool"), patch.object(publisher, "run", return_value=signature):
            with self.assertRaises(ValueError):
                publisher.inspect_apk(self.source)
        with patch.object(publisher, "tool", return_value="tool"), patch.object(publisher, "run", side_effect=[
            "Number of signers: 1\nSigner #1 certificate SHA-256 digest: " + publisher.CERTIFICATE,
            "package: name='other.app' versionCode='5' versionName='1.0.4'",
        ]):
            with self.assertRaisesRegex(ValueError, "package"):
                publisher.inspect_apk(self.source)

    def test_downgrade_and_same_version_replacement_are_rejected(self):
        for code in [1, 2]:
            with self.subTest(code=code), self.assertRaises(ValueError):
                publisher.check_upgrade({"version_code": code, "sha256": "new"}, {"version_code": 2, "sha256": "old"})

    def test_validation_failure_preserves_installer_and_removes_staging(self):
        with patch.object(publisher, "inspect_apk", side_effect=ValueError("corrupt APK")):
            with self.assertRaises(ValueError):
                publisher.publish(self.source, self.destination)
        self.assertEqual(self.target.read_bytes(), b"old")
        self.assertEqual([p.name for p in self.destination.iterdir()], [self.target.name])

    def test_concurrent_publication_is_rejected(self):
        (self.destination / ".publish-installers.lock").mkdir()
        with self.assertRaisesRegex(ValueError, "locked"):
            publisher.publish(self.source, self.destination)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_publication_keeps_old_file_until_atomic_replace_and_creates_backup(self):
        real_replace = publisher.os.replace
        def observed_replace(source, target):
            if target == self.target:
                self.assertEqual(self.target.read_bytes(), b"old")
                self.assertEqual(source.read_bytes(), b"new")
            return real_replace(source, target)
        with patch.object(publisher, "inspect_apk", side_effect=self.metadata), patch.object(publisher, "run", return_value="commit"), patch.object(publisher.os, "replace", side_effect=observed_replace):
            publisher.publish(self.source, self.destination)
        self.assertEqual(self.target.read_bytes(), b"new")
        backups = list((self.root / "installer-backups").glob("*.apk"))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].read_bytes(), b"old")
        records = list(self.destination.glob("release-*.json"))
        self.assertEqual(json.loads(records[0].read_text())["sha256"], publisher.digest(self.target))
        self.assertFalse((self.destination / ".publish-installers.lock").exists())


if __name__ == "__main__":
    unittest.main()
