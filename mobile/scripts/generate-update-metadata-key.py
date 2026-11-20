"""Create ~/.tch15-arm/update-metadata.pem if it is missing. Prints only the public key."""
from pathlib import Path
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec

KEY = Path.home() / ".tch15-arm/update-metadata.pem"


def public_hex(path=KEY):
    key = serialization.load_pem_private_key(path.read_bytes(), password=None)
    return key.public_key().public_bytes(
        serialization.Encoding.DER,
        serialization.PublicFormat.SubjectPublicKeyInfo,
    ).hex()


def main():
    KEY.parent.mkdir(mode=0o700, exist_ok=True)
    if KEY.exists():
        print("Public key: " + public_hex())
        print("Private key already exists at " + str(KEY))
        return 0
    key = ec.generate_private_key(ec.SECP256R1())
    KEY.write_bytes(key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ))
    KEY.chmod(0o600)
    print("Public key: " + public_hex())
    print("Private key written to " + str(KEY))
    print("Pin the public key in config/install.php, mobile/src/app-update.cjs and ReleaseMetadata.kt")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
