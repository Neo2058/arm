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
   - Controller performs:
     - Auth + role check
     - `ActionLog::log()` + ClickHouse
     - Violation logging + alerts for downloads
     - `Storage::disk('s3')->response()` with `inline` + strong no-cache headers

   **B. Admin Form Previews**
   - Special routes: `admin.documents.serve` / `admin.training-materials.serve`
   - Used exclusively by `->getUploadedFileUsing()` in Filament `FileUpload`
   - Admin-only + signed
   - Returns proxy URL so the form can display existing files without direct S3 access

### Key Components

- `DocumentController` + `TrainingController` — streaming logic
- `getUploadedFileUsing` closures in `DocumentResource` / `TrainingMaterialResource`
- Models: `getTemporaryUrl()` / `getFileUrlAttribute()` (generate signed routes)
- `URL::temporarySignedRoute(...)` with 15-minute TTL

**Never** use `Storage::temporaryUrl()` or direct S3 URLs.

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
4. **Per-route**:
   - `->middleware('signed')` for file routes
   - Filament's own `Authenticate` for `/admin`

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

**Environment overrides** in compose for production safety:
- `SESSION_SECURE_COOKIE=false` (HTTP only)
- Internal MinIO endpoint

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
   ├── Signed middleware (files)
   └── Filament (own stack)
            │
            ▼
   File Proxy Controllers
            │
            ▼
   MinIO (internal only)
```

---

**Last updated:** 2026-06-26 (after major 419 + private file fixes)
