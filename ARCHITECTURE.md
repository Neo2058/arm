# Architecture Overview — ТЧ-15 (ARM)

## High-Level Architecture

The application is a **Laravel 12 + Filament 3** monolith deployed via Docker Compose.

**Core principles:**
- **Filament-first** for all administrative CRUD and complex forms.
- **Private-by-default** file storage (no public S3 URLs ever reach the browser).
- **Defense in depth** for access control.
- **Hybrid logging**: operational data in PostgreSQL, analytics/actions in ClickHouse.
- **Same-origin only** for sensitive resources (everything goes through the Laravel app).

### Technology Stack

| Layer              | Technology                          | Notes |
|--------------------|-------------------------------------|-------|
| Backend            | Laravel 12, PHP 8.2                 | - |
| Admin UI           | Filament 3                          | Separate middleware stack |
| User-facing UI     | Blade + Tailwind + React islands    | Islands for complex widgets |
| Databases          | PostgreSQL (main), ClickHouse (analytics), Redis (cache/queue/session) | - |
| Object Storage     | MinIO (S3 compatible)               | All files private |
| Search             | Meilisearch                         | - |
| Web Server         | Nginx (in container) + PHP-FPM      | - |
| Messaging          | Telegram (direct + self-hosted relay) | - |

---

## File Storage & Serving Architecture (Critical)

This is one of the most important and carefully designed parts of the system.

### Design Goals
- **Never** expose direct MinIO URLs to the browser.
- All access must go through the application.
- Temporary signed links with short TTL.
- Inline delivery only (hard to save).
- Every access attempt is logged (views + download attempts as violations).

### Data Flow

1. **Upload (Filament forms)**
   - FileUpload → Livewire `local` disk (`storage/app/private/livewire-tmp`)
   - Temporary files are handled by Livewire's built-in mechanism.

2. **On Form Submit (Create/Edit)**
   - `saveUploadedFiles()` moves the file from temp → final `s3` disk.
   - Path stored in DB as string (e.g. `training-materials/xxx.mp4`).

3. **Serving Files (two paths)**

   **A. User-facing (Documents / Training)**
   - Routes use `->middleware('signed')`
   - Controller: auth + role check, `ActionLog` / ClickHouse, violation alerts on download
   - Stream goes through `App\Services\FileProxyService` (`exists` + `respond`)
   - Callers keep **their own** headers (PDF, video `Accept-Ranges`, admin preview differ on purpose)

   **B. Admin Form Previews**
   - Special routes: `admin.documents.serve` / `admin.training-materials.serve`
   - Used exclusively by `->getUploadedFileUsing()` in Filament `FileUpload`
   - Admin-only + signed
   - Returns proxy URL so the form can display existing files without direct S3 access
   - Filament FileUpload (`disk`, `directory`, `visibility`, `getUploadedFileUsing`) is a fragile contract — do not change it in passing

### Key Components

- `FileProxyService` — only S3 exists-check + stream through the app; never MinIO URLs
- `DocumentController` + `TrainingController` — auth, logging, per-endpoint headers
- `getUploadedFileUsing` closures in `DocumentResource` / `TrainingMaterialResource`
- Models: `getTemporaryUrl()` / `getFileUrlAttribute()` (signed app routes)
- TTL is **not** one number: documents 30 min, quiz refs 60 min, admin preview / training stream 15 min

**Never** use `Storage::temporaryUrl()` or direct S3 URLs for new code.  
`NaryadViewerController` and `RospisiController` still do; that is remaining debt.

---

## Access Control

### Layers (in order of execution)

1. **Nginx** — basic rate limiting, body size, static caching
2. **Laravel global web middleware**
3. **Route group middleware** (most pages):
   ```php
   Route::middleware([
       'auth',
       CheckUserExistence::class,
       CheckDeviceBinding::class,
       CheckDynamicBarrier::class
   ])
   ```
4. **Per-route / per-controller**:
   - `->middleware('signed')` for file routes
   - `EnsureInstructor` — all `/journal*` (`routes/journal.php` + `TCHMJournalController`)
   - `EnsureDispatcher` — all `/naryad*` (`routes/naryad.php` + Naryad controllers)
   - `EnsureUchetStaff` — `/uchet*` (`routes/uchet.php`)
   - Filament's own `Authenticate` for `/admin`

Role checks in PHP go through `User` helpers (`isAdmin()`, `isInstructor()`, `isDispatcher()`, `canBypassAccessBarriers()`, `canAccessByRoles()`). Canonical dispatcher role is `dispatcher`; `naryadchik` is only a `UserRole::safeFrom()` alias.

### Routes

`routes/web.php` is the public entry + auth wrapper. Domain files (same middleware group):

| File | Domain |
|---|---|
| `account.php` | main menu, barrier, device |
| `documents.php` | documents, signed PDF, quizzes |
| `training.php` | training, rosisi, signed media |
| `work.php` | naryady viewer, `POST /naryady/search-people`, shifts, podstroiki |
| `journal.php` | TCHM journal + instructor naryad search |
| `naryad.php` | dispatcher planning, print (`/naryad/print`) |
| `uchet.php` | operator accounting (cards, LS, LSBUH, period close) |
| `support.php` | backstage, bugs, Telegram, YooKassa |

### Custom Middlewares

| Middleware                | Purpose                              | Admin Bypass | Livewire Skip | Notes |
|---------------------------|--------------------------------------|--------------|---------------|-------|
| `CheckUserExistence`      | Block deleted/deactivated users      | No (but admin usually active) | Yes | Invalidates session |
| `CheckDeviceBinding`      | Device approval                      | Yes          | Yes           | - |
| `CheckDynamicBarrier`     | One-time barrier check               | Yes          | Yes           | Uses session flag |

**Important**: Livewire and admin serve paths are explicitly exempted in the middlewares to prevent 419 loops during AJAX.

### Filament Admin Panel

- Own middleware stack (defined in `AdminPanelProvider`)
- Includes custom `VerifyCsrfToken` (our subclass)
- CSRF meta tag injected via render hook
- Does **not** run the three custom Check middlewares (by design)

---

## Logging & Notifications

### Two Logging Systems

1. **Operational + Violations**
   - `ActionLog::log()`
   - Stored in PostgreSQL
   - Used for document views, training views, download attempts

2. **Analytics**
   - `ClickHouseService::log()`
   - `user_actions` table
   - High-volume events

### Violation Flow (Downloads/Views)

When a non-admin tries to download or sometimes view:
1. Log as violation in `ActionLog`
2. `AdminNotificationService::notify('violation', ...)`
3. Sends to:
   - Database (AdminNotification)
   - Email
   - Telegram (via `TelegramService`)

---

## Docker & Infrastructure

**Services** (docker-compose.prod.yml):

- `app` (PHP-FPM + Laravel)
- `nginx`
- `postgres`
- `redis`
- `minio`
- `clickhouse`
- `meilisearch`

**Key Design Choices**:
- All services on internal `backend` network
- Only `nginx` publishes ports
- Named volume `app_storage` for Laravel storage
- `minio_data` volume
- Entrypoint ensures directories + bucket existence

**Environment overrides** in compose:
- Production (`docker-compose.prod.yml`): HTTPS, `SESSION_SECURE_COOKIE=true`
- Local compose may still be HTTP
- Internal MinIO endpoint (browser never talks to it)

---

## CSRF & Livewire Considerations

Due to the combination of:
- Docker
- IDN (punycode) domain
- Heavy Livewire + Filament file uploads
- Custom middlewares

We had to apply several mitigations:
- Custom `VerifyCsrfToken` with `livewire/*` exceptions + `X-Livewire` header check
- Explicit skips in Check middlewares
- Forced safe session cookie settings
- Nginx buffering improvements
- CSRF meta tag render hook in Filament

These are documented in code comments.

---

## Important Design Decisions

1. **Private storage only** — security requirement. Direct browser access to MinIO is forbidden.
2. **Signed routes + proxy** — temporary, revocable, logged access.
3. **Inline delivery** — makes casual downloading harder.
4. **Admin-only preview URLs** — used only inside Filament forms.
5. **Separate middleware stack** for Filament vs user routes.
6. **ClickHouse for analytics** — keeps PostgreSQL clean.
7. **Role enum + User helpers** — no scattered `in_array($role, ['admin', …])`.
8. **Naryad split** — `App\Http\Controllers\Naryad\{Planning,Setka,Catalogs,Breakdowns,Personnel,Print}Controller`; hours in `NaryadHoursCalculator` / Arm services.

---

## Diagrams (Text)

```
Browser
   │
   ▼
Nginx (static + proxy)
   │
   ▼
Laravel (web group)
   ├── Auth + 3 Check Middlewares
   ├── EnsureInstructor / EnsureDispatcher (domain)
   ├── Signed middleware (files)
   └── Filament (own stack)
            │
            ▼
   FileProxyService (documents / training)
            │
            ▼
   MinIO (internal only)
```

---

**Last updated:** 2026-10-02 (print, naryad people search, uchet routes)
