# Technical Debt & Refactoring Roadmap

**Project:** ТЧ-15 (ARM)  
**Last reviewed:** 2026-06-26 (after stabilization of file uploads and 419 issues)

---

## Critical / High Priority

### 1. File Proxy Duplication
**Location:** `DocumentController`, `TrainingController`, models (`getTemporaryUrl`), Filament resources

**Problem:**
- Almost identical streaming + logging + violation logic duplicated.
- Admin serve methods also have similar patterns.
- Signed URL generation scattered.

**Impact:** Hard to maintain security rules consistently. Easy to miss logging on new file types.

**Suggested fix:**
- Create `App\Services\FileProxyService`
- Extract common streaming + header logic
- Move violation detection into the service
- Use in both controllers and potentially in a middleware

### 2. Middleware Duplication & Complexity
**Files:**
- `CheckUserExistence`
- `CheckDeviceBinding`
- `CheckDynamicBarrier`

**Problems:**
- Similar admin bypass logic
- Path exceptions maintained in multiple places
- Livewire skips added reactively (after 419 incidents)
- Role checks are string-based (`str_contains($role, 'admin')`)

**Impact:** Fragile. Adding new protected areas or roles is error-prone.

**Suggestions:**
- Introduce a single `AccessControl` middleware + configuration
- Or move more logic to Policies + FormRequest
- Create a `Role` helper / enum with clear methods (`isAdmin()`, `canBypassBarrier()`)

### 3. CSRF + Livewire Workarounds
**Files:**
- `app/Http/Middleware/VerifyCsrfToken`
- `bootstrap/app.php`
- `AdminPanelProvider`
- `docker-compose.prod.yml` + `config/session.php`

**Current state:**
- Custom exception list for `livewire/*`
- Header-based bypass
- Forced `SESSION_SECURE_COOKIE=false`
- Explicit domain handling because of punycode domain + Docker

**Risk:** These mitigations are necessary today but hide potential deeper issues.

**Action items:**
- Document exactly why each workaround exists
- Re-evaluate when HTTPS is enabled
- Consider moving sensitive Livewire components to a separate authenticated API (future)

---

## Medium Priority

### 4. Logging is Scattered
**Components:**
- `ActionLog` (Postgres)
- `ClickHouseService`
- `AdminNotificationService`
- Inline calls in controllers and TrainingContentService

**Problems:**
- No single place that decides "this is a violation"
- Violation logic is duplicated between document download and training streaming
- Hard to get a complete audit trail

**Recommendation:**
- Central `AuditService` or `AccessLogger`
- Clear separation: "Event" vs "Violation"
- Consistent use of enums for event types

### 5. Filament Resource Closures
**Files:**
- `DocumentResource.php`
- `TrainingMaterialResource.php`

**Problem:**
- Large anonymous functions inside `->getUploadedFileUsing()`
- Business logic mixed with form definition

**Fix:**
- Extract to dedicated methods: `getDocumentFileInfo(string $path)`, etc.
- Or create `FileUploadConfigurator` classes

### 6. Hardcoded Roles and Strings
Seen in:
- All three middlewares
- Controllers (`in_array(..., ['super_admin', 'admin'])`)
- Resources

**Better approach:**
- Use the `Role` enum consistently
- Add methods like `User::isAdmin()`, `User::canAccessAdminPanel()`

### 7. Large `web.php`
The protected route group is very long.

**Suggestions:**
- Split into domain route files (`routes/documents.php`, `routes/training.php`, `routes/naryad.php`, etc.)
- Use `Route::middleware(...)->group(...)` in separate files

---

## Lower Priority / Nice to Have

| Area                        | Debt                                                                 | Priority |
|----------------------------|----------------------------------------------------------------------|----------|
| **Testing**                | Almost no automated tests                                            | High    |
| **Error handling on S3**   | Few try/catch around `Storage::disk('s3')` operations                | Medium  |
| **Entrypoint.sh**          | Mix of shell + embedded PHP (`php -r`). Hard to test                 | Low     |
| **Session configuration**  | Multiple overrides between `.env`, `config/session.php`, compose     | Medium  |
| **Nginx config**           | Some duplication between HTTP and commented HTTPS blocks             | Low     |
| **Model accessors**        | `getFileUrlAttribute` etc. can throw during eager loading in some cases | Low  |
| **Backup strategy**        | No documented backup process for postgres / minio_data / clickhouse  | High    |
| **HTTPS**                  | Still not implemented (critical for removing many workarounds)       | High    |
| **Monitoring**             | No health checks beyond basic, no metrics                            | Medium  |
| **Code style / docs**      | Some services and controllers lack PHPDoc                            | Low     |

---

## Known Workarounds (Track These)

1. **Livewire 419 mitigations** (see `VerifyCsrfToken` and middlewares)
2. **Admin-only file preview URLs** (`admin.serve-*`) — used because Filament FileUpload needs a fetchable URL
3. **Forcing `SESSION_SECURE_COOKIE=false`** until HTTPS
4. **Explicit Livewire path skips** in Check middlewares
5. **Punycode vs Cyrillic** handling in `APP_URL` and `SESSION_DOMAIN`

**When HTTPS is enabled**, many of the above should be revisited.

---

## Suggested Refactoring Order (Recommended)

1. **FileProxyService** + centralize streaming/violation logic (highest ROI)
2. **Unify role checks** + improve middlewares
3. **Add basic feature tests** for file upload → create → proxy download flow
4. **Extract Filament file upload configuration**
5. **AuditService** for logging
6. **Split routes**
7. **Backup & monitoring** (operational debt)

---

## Areas Safe to Extend Without Major Refactoring

- New Filament resources / pages
- New user-facing Blade pages (if they follow existing patterns)
- Additional Telegram notification types
- New columns in existing journal/naryad modules
- ClickHouse event types (as long as you use the service)

---

## How to Work With This Debt

- When adding **new file-related features**, always go through the future `FileProxyService`.
- When touching **access control**, update all three middlewares or (better) create a common helper first.
- Before enabling **HTTPS**, create a task to clean up session/CSRF workarounds.
- Keep this file updated after any significant change.

---

**Goal:** Keep the application maintainable while the core (private files + strict access) remains solid.
