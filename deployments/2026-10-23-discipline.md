# Discipline module production deployment

Deployed on 23 October 2026 to https://www.xn---15-7edz.xn--p1ai/discipline-admin/discipline-monitor . Instructor entry: `/discipline`.

Application commits: `ed47738` and `310c04e22db487fa6fc10b59ba0d43df4acba9e5`. Production advanced fast-forward from `59781c9`; the already published relay fix `b2fc34b` was included. Uncommitted mobile and other parallel development changes were excluded. Existing modified Filament assets were preserved.

Maintenance window: **22:28:10-22:28:42 Europe/Moscow**, or 19:28:10-19:28:42 UTC, approximately 32 seconds. Maintenance mode was removed after successful migration and checks.

## Runtime

- Project: `/home/deploy/arm`, PHP 8.2.32, PostgreSQL 15.
- Application image: `arm-discipline:310c04e`, ID `sha256:92feb169ece21e852c23a3baed0bb688920ac02c3de51e2ecf2a4cad73e2e0ba`.
- Built from the exact previous application runtime with clamdscan added; PHP and the other existing runtime dependencies were preserved.
- Previous image retained as `arm-app:before-discipline-310c04e`, ID `sha256:bf32f89aece2af2d16edd369124ab157d2c04187cbe52787064e2ca67bb94362`.
- ClamAV pinned to `clamav/clamav@sha256:e19130cdebaeacc71fa46eab3ed6f69941f2f22aa5e08d55e9197f8edf43ce5e`, limited to 2 GiB and one CPU, attached only to the internal Docker network, without published ports.
- Server `.env` contains `DISCIPLINE_APP_IMAGE=arm-discipline:310c04e` and `COMPOSE_FILE=docker-compose.prod.yml:docker-compose.discipline.yml:docker-compose.discipline.runtime.yml`.
- The local runtime overlay pins ClamAV and is excluded from Git. Use the full Compose configuration for future operations; plain `docker compose` in the project directory reads the saved `COMPOSE_FILE`.
- Sessions and the existing queue configuration were preserved. Redis was not flushed. Only configuration, route, event, view and Filament caches were regenerated.
- Only migration `2026_10_23_000001_create_discipline_module` was applied: six new tables and two allowed role values.
- All 16 existing users and their roles were preserved. The owner confirmed there are no `nachalnik` or `tormoznik` accounts yet. Assign these roles through the existing administrator user form when the accounts are created. No test users or cards were committed to the production database.

## Backups and rollback

Private server directory: `/home/deploy/backups/discipline-20261023T192020Z`, mode 0700.

- `postgres-final.dump`: PostgreSQL custom dump taken while application writes were stopped.
- `minio-final.tar.gz`: MinIO data volume archived while MinIO was stopped.
- `app-storage-final.tar.gz`: application storage volume.
- `runtime-files.before.tar.gz`, `working-tree.patch`, `previous-commit`, `previous-image`, `env.before`, `app-container-before.json`: previous assets, caches, source state and configuration. Configuration backups contain secrets and must remain private.
- `SHA256SUMS`: verified checksums of the final archives.
- `rollback.sh`: application rollback prepared for this initial cutover; it deliberately does not restore the whole database automatically.

Both the preliminary and final database dumps were restored into separate verification databases. The final MinIO archive was restored into a temporary volume/container without external ports: 45 objects were accessible, and a streamed object checksum matched the live store. Verification databases, temporary containers and restore volumes were removed. Uploaded smoke-test objects were also removed.

Before a future rollback, review the then-current roles and reports. The previous application cannot interpret the new roles. Restoring an old database dump would lose later data. Do not blindly reuse the initial rollback script after subsequent deployments or role assignments.

## Validation

- Clean local release: 330 tests passed and Vite build completed.
- Server PHP 8.2.32, isolated container without network: 329 tests passed, one skipped because the optional ARMNRD/Datanrd reference dump was absent.
- Module migration on a restored production schema; create, submit with file, replay, return, resubmit, accept and history checks. Test-user/card SQL changes were rolled back.
- Real MinIO private storage and byte-exact download; real ClamAV rejected EICAR. Scanner and cached configuration also verified as `www-data`.
- Application, PostgreSQL, MinIO and ClamAV healthy. Nginx configuration validated and reloaded.
- HTTPS login pages and all compiled assets returned 200; module pages redirected guests to login; mobile documents API returned 401 without a token; login POST without CSRF returned 419.

A production browser session using a real manager account remains to be checked once that account exists. The searching split, Qt network API and offline queue are separate work.
