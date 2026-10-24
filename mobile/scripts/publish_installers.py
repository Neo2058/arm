"""Verified APK publication; IPA receives archive checks, not Apple signature verification."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import plistlib
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = "ru.tch15.arm"
CERTIFICATE = "2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f"


def run(*args):
    result = subprocess.run([str(a) for a in args], capture_output=True, text=True)
    if result.returncode:
        raise ValueError("Verification command failed: " + Path(str(args[0])).name)
    return result.stdout


def digest(path):
    value = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            value.update(block)
    return value.hexdigest()


def tool(name):
    found = shutil.which(name)
    if found:
        return found
    sdk = os.environ.get("ANDROID_HOME") or os.environ.get("ANDROID_SDK_ROOT") or str(Path.home() / "Library/Android/sdk")
    candidates = list((Path(sdk) / "build-tools").glob("*/" + name))
    if not candidates:
        raise ValueError("Android build-tools required: " + name)
    return str(max(candidates, key=lambda p: tuple(int(n) for n in re.findall(r"\d+", p.parent.name))))


def inspect_apk(path):
    signature = run(tool("apksigner"), "verify", "--verbose", "--print-certs", path)
    # Build-tools 37 reports scheme-specific signers; 36 reports Signer #1.
    certs = re.findall(r"^(?:Signer #\d+|V[\d.]+ Signer:)\s+certificate SHA-256 digest: ([a-fA-F0-9]{64})", signature, re.MULTILINE)
    signer_count = re.search(r"^Number of signers: (\d+)\s*$", signature, re.MULTILINE)
    if not signer_count or signer_count[1] != "1" or {c.lower() for c in certs} != {CERTIFICATE}:
        raise ValueError("APK certificate does not match the approved release certificate")
    info = run(tool("aapt"), "dump", "badging", path)
    match = re.search(r"package: name='([^']+)' versionCode='(\d+)' versionName='([^']*)'", info)
    if not match or match[1] != PACKAGE or int(match[2]) < 1:
        raise ValueError("Unexpected APK package or version")
    manifest = run(tool("aapt"), "dump", "xmltree", path, "AndroidManifest.xml")
    if re.search(r"android:(?:debuggable|testOnly)\([^)]*\)=\(type 0x12\)0xffffffff", manifest):
        raise ValueError("Debug/test-only APK publication is forbidden")
    return {"package": match[1], "version_code": int(match[2]), "version": match[3],
            "certificate_sha256": CERTIFICATE, "sha256": digest(path)}


def check_upgrade(candidate, previous):
    if candidate["version_code"] < previous["version_code"]:
        raise ValueError("APK downgrade is forbidden")
    if candidate["version_code"] == previous["version_code"] and candidate["sha256"] != previous["sha256"]:
        raise ValueError("Different APK with the same versionCode is forbidden")


def inspect_ipa(path):
    with zipfile.ZipFile(path) as archive:
        names = [n for n in archive.namelist() if re.fullmatch(r"Payload/[^/]+\.app/Info.plist", n)]
        if len(names) != 1 or archive.getinfo(names[0]).file_size > 1024 * 1024:
            raise ValueError("Invalid IPA metadata")
        if plistlib.loads(archive.read(names[0])).get("CFBundleIdentifier") != PACKAGE:
            raise ValueError("Unexpected IPA bundle identifier")
    return {"package": PACKAGE, "sha256": digest(path), "signature_verified": False}


def stage(source, directory):
    descriptor, name = tempfile.mkstemp(prefix=".installer-", dir=directory)
    path = Path(name)
    try:
        with os.fdopen(descriptor, "wb") as output, source.open("rb") as incoming:
            shutil.copyfileobj(incoming, output)
            output.flush()
            os.fsync(output.fileno())
        return path
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def publish(source, destination):
    suffix = source.suffix.lower()
    if suffix not in (".apk", ".ipa") or not source.is_file():
        raise ValueError("Expected an existing .apk or .ipa file")
    destination.mkdir(parents=True, exist_ok=True)
    lock = destination / ".publish-installers.lock"
    try:
        lock.mkdir(mode=0o700)
    except FileExistsError:
        raise ValueError("Publication is locked; inspect the previous process before removing the lock")
    staged = None
    try:
        target = destination / ("tch15-android.apk" if suffix == ".apk" else "tch15-ios.ipa")
        if target.is_symlink():
            raise ValueError("Installer destination must not be a symlink")
        staged = stage(source, destination)
        inspect = inspect_apk if suffix == ".apk" else inspect_ipa
        metadata = inspect(staged)
        if target.exists():
            previous = inspect(target)
            if suffix == ".apk":
                check_upgrade(metadata, previous)
            if metadata["sha256"] == previous["sha256"]:
                print("Already published: " + target.name)
                return
            backups = destination.parent.parent / "installer-backups"
            if backups.is_symlink():
                raise ValueError("Backup directory must not be a symlink")
            backups.mkdir(mode=0o700, exist_ok=True)
            backups.chmod(0o700)
            backup = backups / (previous["sha256"] + suffix)
            if backup.exists():
                if backup.is_symlink() or digest(backup) != previous["sha256"]:
                    raise ValueError("Existing installer backup is invalid")
            else:
                with backup.open("xb") as output, target.open("rb") as incoming:
                    shutil.copyfileobj(incoming, output)
                    output.flush()
                    os.fsync(output.fileno())
        metadata["published_at"] = datetime.now(timezone.utc).isoformat()
        # Identifies the publisher checkout, not proof of the APK's build provenance.
        metadata["publisher_git_commit"] = run("git", "-C", ROOT, "rev-parse", "HEAD").strip()
        record = destination / ("release-" + metadata["sha256"] + ".json")
        descriptor, name = tempfile.mkstemp(prefix=".release-", dir=destination)
        record_stage = Path(name)
        try:
            with os.fdopen(descriptor, "w") as output:
                json.dump(metadata, output, ensure_ascii=False, indent=2)
                output.write("\n")
                output.flush()
                os.fsync(output.fileno())
            record_stage.chmod(0o644)
            os.replace(record_stage, record)
        finally:
            record_stage.unlink(missing_ok=True)
        staged.chmod(0o644)
        os.replace(staged, target)
        if digest(target) != metadata["sha256"]:
            raise ValueError("Post-publication hash mismatch")
        print("Published: " + target.name + " SHA256=" + metadata["sha256"])
        if suffix == ".ipa":
            print("IPA structure checked; Apple signature NOT verified")
    finally:
        if staged:
            staged.unlink(missing_ok=True)
        lock.rmdir()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("files", nargs="+", type=Path)
    parser.add_argument("--destination", type=Path, default=ROOT / "public/downloads")
    args = parser.parse_args()
    try:
        for source in args.files:
            publish(source, args.destination.resolve())
    except (ValueError, OSError) as error:
        print("Publication refused: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
