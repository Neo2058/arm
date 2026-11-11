# Instructor journal — production release 25 October 2026

Released code `ac884d343c3c36c7eb569fc90179351ff9ec98be`, Android 1.0.7 versionCode 8. Entry: instructor «Поручения» → «Журнал инструктора». Shared web/mobile summary, personal tasks with pending/done filters, column drivers and own recorded normative dates. This release is read-only for journal data; mutations remain in web. Discipline report delivery/queue is unchanged.

## Deployment

Production checkout advanced from 93af37c through existing security commit 85b4afa to ac884d3. Four previously active local PHP-hardening files matched 85b4afa byte-for-byte and were staged before fast-forward; no security settings were reverted. Existing Filament assets/staged changes and untracked server configuration preserved. Runtime image remains `arm-discipline:php8.2.35-20261025`; no build/recreate, migrations or dependency upgrades. Config/routes cached; php-fpm config validated, then master PID 1 gracefully reloaded via USR2. Container uptime preserved. Application maintenance approximately **23:06:46–23:06:52 Europe/Moscow** (20:06:46–20:06:52 UTC), six seconds.

Private backup: `/home/deploy/backups/mobile-journal-20261025T200642Z`, mode 0700. Contains configuration, previous source/index patches, runtime routes/controller/cache archive, previous APK 1.0.6 and PostgreSQL custom dump. Dump TOC checked with pg_restore -l and checksum saved; no live database restore performed for this additive read release.

## Installer and checks

Signed APK SHA256 `cf696dd3786490f514b511b37c1a67c6df611c88925b2826e803b928c83d7afa`. Certificate SHA256 `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`, same as installed releases. Local publisher verified signature, package, release flags and increasing versionCode against 1.0.6. Remote publication used directory lock, expected prior/staged hashes, backup and same-filesystem atomic rename. Metadata: `/downloads/release-cf696dd3786490f514b511b37c1a67c6df611c88925b2826e803b928c83d7afa.json`.

Full PHP suite **366 tests / 1806 assertions**; focused new API + existing web access **39 tests / 126 assertions**; **20 JS tests** pass. Android export and signed build pass. Isolated Android/API native test covered instructor login, journal navigation, summary, personal tasks, completed filter, own column crew and overdue normative dates. Final production-address APK installed/launched separately after clearing test credentials. No test users or journal records created in production. iOS not built/tested.

App/ClamAV healthy. New routes registered and checked as www-data. Final HTTPS smoke: new summary/tasks/crew and existing discipline routes return 401 to guests; login/install return 200. `/install/android` downloaded successfully and SHA256 matched the verified APK. Real instructor workflow on production remains a user check with an existing account; functional tests used isolated fixtures.

## Rollback

Preserve current data and PHP-hardening configuration. Restore only `routes/api.php` and `app/Http/Controllers/TCHMJournalController.php` from runtime.before.tar.gz; after route restoration remove the added JournalController/JournalOverview files if unchanged, rebuild route cache and gracefully reload FPM. Restore prior installer atomically. Do not reset the checkout, restore old Docker/compose/env files or restore the database dump for a read-view rollback. Android rejects a lower versionCode over 8: existing devices require a corrective signed build with a higher versionCode; uninstalling deletes local discipline drafts.
