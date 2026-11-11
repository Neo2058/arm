# Mobile discipline API — 24 October 2026

Production updated from 2b8d41d to 15b1821 via fast-forward. Backup: `/home/deploy/backups/mobile-discipline-20261024T061200Z` (0700); database custom dump, configuration, source patch, route cache and previous Android installer retained privately. Dump TOC read successfully. No migrations, role assignments, test accounts or data changes. Existing image, ClamAV/MinIO and session settings preserved. PHP app container restarted, config/routes cached. Six Sanctum + active-user + instructor routes registered.

Initial HTTPS smoke caught unreadable new controller caused by restrictive deployment umask; new tracked files corrected to 0644 and directory to 0755, PHP www-data syntax check passed. Final guest API 401; login/install 200; app healthy; Nginx config valid; ClamAV healthy. Existing staged production assets preserved in backup and not reset.

Published signed Android 1.0.5 versionCode 6 to `/downloads/tch15-android.apk` atomically after SHA256 verification: `6dec1ee75195e79ea10b0b13f699b7d75a1fc5fb5d8c806010bf7865644cdc59`. Previous installer backed up. APK signer SHA256 `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`, identical to existing release. Production API URL is canonical HTTPS. Instructor/manager flow tested in isolated local native/API harness; real manager check pending account creation.

Rollback: preserve current user data; restore previous controller absence and `routes/api.php` from 2b8d41d (only these new backend files), rebuild route cache and restart PHP; restore backed-up installer. Do not reset the production checkout or restore DB dump for this additive API release. No schema/data rollback is required. Review current checkout before applying an eventual rollback.


## Persistent drafts and queue — Android 1.0.6

Published commit `2530f946cf35000a12cdbb8512ab2bb7fe8fe3e2`, Android versionCode 7. Concurrent PDF mitigation `0622b0a` and verified publisher `f91f91a` preserved. No additional API changes, migrations or PHP restart. App/ClamAV healthy. Previous APK 1.0.5 and source patch retained privately in `/home/deploy/backups/mobile-queue-20261024T063837Z`.

New APK SHA256: `6ea9d19f6f0ebf0283bfbcc144113dae21838d27662702da0e96a7891c983f2f`. Local verified publisher checked pinned signing certificate, package, release flags and increasing versionCode against previous 1.0.5, then staged the APK and public release metadata. Remote publication used a directory lock, expected previous hash, verified staged hash, private backup and same-filesystem atomic rename. Metadata: `/downloads/release-6ea9d19f6f0ebf0283bfbcc144113dae21838d27662702da0e96a7891c983f2f.json`. Public HTTPS APK downloaded and its SHA256 matched. API guest 401 and install page 200 rechecked.

Twenty JS tests pass. Native isolated Android checks: rapid text entry, private copy of PDF, draft recovery after force-stop, offline identity/card recovery, frozen queued request recovery, automatic delivery after local API returned (exactly one report and PDF), receipt UI, explicit logout clearing and fresh final APK installation/startup. No production test users/data created. Offline delivery runs while app is active; reopening resumes it, but delivery with the process closed and background push are outside this release. iOS not built/tested.

For rollback of the mobile queue alone, restore backed-up APK 1.0.5 atomically; API 15b1821 remains compatible. Do not restore the database or reset unrelated source changes. Native Android rejects installing lower versionCode 6 over installed 7: rollback for existing devices needs a corrective signed build with a higher versionCode (uninstalling would erase local drafts).
