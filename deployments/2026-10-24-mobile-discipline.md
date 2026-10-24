# Mobile discipline API — 24 October 2026

Production updated from 2b8d41d to 15b1821 via fast-forward. Backup: `/home/deploy/backups/mobile-discipline-20261024T061200Z` (0700); database custom dump, configuration, source patch, route cache and previous Android installer retained privately. Dump TOC read successfully. No migrations, role assignments, test accounts or data changes. Existing image, ClamAV/MinIO and session settings preserved. PHP app container restarted, config/routes cached. Six Sanctum + active-user + instructor routes registered.

Initial HTTPS smoke caught unreadable new controller caused by restrictive deployment umask; new tracked files corrected to 0644 and directory to 0755, PHP www-data syntax check passed. Final guest API 401; login/install 200; app healthy; Nginx config valid; ClamAV healthy. Existing staged production assets preserved in backup and not reset.

Published signed Android 1.0.5 versionCode 6 to `/downloads/tch15-android.apk` atomically after SHA256 verification: `6dec1ee75195e79ea10b0b13f699b7d75a1fc5fb5d8c806010bf7865644cdc59`. Previous installer backed up. APK signer SHA256 `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`, identical to existing release. Production API URL is canonical HTTPS. Instructor/manager flow tested in isolated local native/API harness; real manager check pending account creation.

Rollback: preserve current user data; restore previous controller absence and `routes/api.php` from 2b8d41d (only these new backend files), rebuild route cache and restart PHP; restore backed-up installer. Do not reset the production checkout or restore DB dump for this additive API release. No schema/data rollback is required. Review current checkout before applying an eventual rollback.
