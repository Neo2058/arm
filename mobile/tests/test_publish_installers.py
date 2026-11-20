import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec

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
        self.notes = self.root / "notes.json"
        self.notes.write_text(json.dumps({"2": ["Дни недели в календаре", "x" * 200, 1, ""]}), encoding="utf-8")
        key = ec.generate_private_key(ec.SECP256R1())
        self.key_path = self.root / "update-metadata.pem"
        self.key_path.write_bytes(key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        ))
        self.pub = key.public_key().public_bytes(
            serialization.Encoding.DER,
            serialization.PublicFormat.SubjectPublicKeyInfo,
        ).hex()

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
            publisher.publish(self.source, self.destination, notes_path=self.notes, metadata_key_path=self.key_path, pinned_public_key=self.pub)
        self.assertEqual(self.target.read_bytes(), b"new")
        backups = list((self.root / "installer-backups").glob("*.apk"))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].read_bytes(), b"old")
        records = list(self.destination.glob("release-*.json"))
        self.assertEqual(json.loads(records[0].read_text())["sha256"], publisher.digest(self.target))
        latest = json.loads((self.destination / "android-latest.json").read_text())
        self.assertEqual(latest["package"], publisher.PACKAGE)
        self.assertEqual(latest["version_code"], 2)
        self.assertEqual(latest["url"], "/install/android")
        self.assertEqual(latest["size"], len(b"new"))
        self.assertNotIn("publisher_git_commit", latest)
        self.assertEqual(latest["changes"], ["Дни недели в календаре", "x" * 160])
        self.assertEqual(latest["certificate_sha256"], publisher.CERTIFICATE)
        self.assertRegex(latest["signature"], r"^[0-9a-f]{128,180}$")
        serialization.load_der_public_key(bytes.fromhex(self.pub)).verify(
            bytes.fromhex(latest["signature"]),
            publisher.canonical_payload(latest).encode("utf-8"),
            ec.ECDSA(hashes.SHA256()),
        )
        self.assertFalse((self.destination / ".publish-installers.lock").exists())

    def test_publication_requires_release_notes(self):
        with patch.object(publisher, "inspect_apk", side_effect=self.metadata), patch.object(publisher, "run", return_value="commit"):
            with self.assertRaisesRegex(ValueError, "Release notes"):
                publisher.publish(self.source, self.destination, notes_path=self.root / "missing.json", metadata_key_path=self.key_path, pinned_public_key=self.pub)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_publication_rejects_empty_notes_for_version(self):
        self.notes.write_text(json.dumps({"2": [], "1": ["старая версия"]}), encoding="utf-8")
        with patch.object(publisher, "inspect_apk", side_effect=self.metadata), patch.object(publisher, "run", return_value="commit"):
            with self.assertRaisesRegex(ValueError, "Release notes are required"):
                publisher.publish(self.source, self.destination, notes_path=self.notes, metadata_key_path=self.key_path, pinned_public_key=self.pub)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_publication_requires_metadata_signing_key(self):
        with patch.object(publisher, "inspect_apk", side_effect=self.metadata), patch.object(publisher, "run", return_value="commit"):
            with self.assertRaisesRegex(ValueError, "Metadata signing key"):
                publisher.publish(self.source, self.destination, notes_path=self.notes, metadata_key_path=self.root / "missing.pem", pinned_public_key=self.pub)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_publication_rejects_unpinned_metadata_key(self):
        with patch.object(publisher, "inspect_apk", side_effect=self.metadata), patch.object(publisher, "run", return_value="commit"):
            with self.assertRaisesRegex(ValueError, "does not match the pinned public key"):
                publisher.publish(self.source, self.destination, notes_path=self.notes, metadata_key_path=self.key_path)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_pinned_public_key_matches_php_and_app(self):
        root = Path(__file__).resolve().parents[2]
        php = (root / "config/install.php").read_text(encoding="utf-8")
        js = (root / "mobile/src/app-update.cjs").read_text(encoding="utf-8")
        kotlin = (root / "mobile/modules/arm-notification-poller/android/src/main/java/ru/tch15/arm/push/ReleaseMetadata.kt").read_text(encoding="utf-8")
        self.assertEqual(len(publisher.PINNED_PUBLIC_KEY), 182)
        self.assertIn(publisher.PINNED_PUBLIC_KEY, php)
        self.assertIn(publisher.PINNED_PUBLIC_KEY, js)
        self.assertIn(publisher.PINNED_PUBLIC_KEY, kotlin)

    def test_canonical_payload_matches_php(self):
        message = publisher.canonical_payload({
            "package": publisher.PACKAGE,
            "version": "1.1.12",
            "version_code": 28,
            "sha256": "a" * 64,
            "size": 9,
            "certificate_sha256": publisher.CERTIFICATE,
            "changes": ["Дни недели в календаре"],
        })
        self.assertEqual(message, '{"certificate_sha256":"2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f","changes":["Дни недели в календаре"],"package":"ru.tch15.arm","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size":9,"url":"/install/android","version":"1.1.12","version_code":28}')


if __name__ == "__main__":
    unittest.main()
