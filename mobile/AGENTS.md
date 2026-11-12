# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

Playback uses expo-video/expo-audio matching SDK 57, not expo-av 16 (native JSI crash on RN 0.86).
Keep expo-asset in the SDK 57 range: unconstrained peer installation can pull SDK 58.
Document reading uses PDF.js inside WebView from fetched bytes — never `WebView source={{ uri: pdfUrl }}` (Android DownloadManager). `expo-screen-capture` FLAG_SECURE + screenshot listener; capture attempts POST `/documents/{id}/violation`. Do not list `expo-screen-capture` in `app.config.js` plugins: the package has no `app.plugin.js` and prebuild then loads ESM `build/ScreenCapture.js`. Native code is autolinked from the dependency.
Long PDFs: one bounded canvas with page navigation; transfer bytes through acknowledged 64 KiB chunks
into a small HTML shell. Never embed a large document in source.html or render all pages eagerly.
Tabs: documents, quizzes (`QuizService` on the server), naryads (personal TXT + in-app PDF), training, profile.
Shared phone book is `GET /phones` (paginated) and `POST /phones`, backed by PostgreSQL phone_contacts.
All active users may add contacts; name and normalized unique number are shared with web users.
Keep form values on network errors; duplicate retries must not create another record.
Native dependency changes require a new APK and an install/start smoke test; JS export alone is insufficient.
Preserve local native signing configuration when regenerating ignored android/ios directories.


Discipline: instructor-only tab, shared Laravel DisciplineService behind Sanctum routes. See DISCIPLINE.md. Keep one frozen operation UUID, version, response and file list on uncertain network errors; never silently replace the request on retry. Persistent drafts and frozen operations use the private FileSystem journal (storage.js), scoped to API URL + user ID; queue.cjs is tested with injected storage. Persist before sending, keep UUID/version/files across restarts. Automatic retries run only while active; auth/conflict failures block. Reauthenticate preserves drafts, logout deletes current scope. See DISCIPLINE.md. Files use token-authenticated byte requests and in-app viewing, not public MinIO URLs. DocumentPicker/Crypto match SDK 57 and require a new native binary.

Journal read views: JournalScreen exports overview/tasks/crew, reached from DisciplineList without adding another bottom tab. Token-only GET /journal{,/tasks,/crew}; no long-lived journal cache or mutations. See docs/mobile/JOURNAL.md; keep journal tasks distinct from discipline submissions.

ProfileScreen manages Telegram through token-authenticated /account/telegram GET/POST/DELETE.
One-time bot links stay in memory only, never logs or persistent drafts; refresh status on return from Telegram.
Version is visible in profile. Icons use local react-native-svg paths in components/AppIcon.js.


## Личные профили и логины — 26 октября 2026

- `/account` GET/PATCH и `/api/mobile/account/profile` GET/PATCH используют один `SelfProfile` и рабочую карточку `arm_personnel`. Телефоны, личные и кадровые поля можно редактировать самостоятельно; профиль и картотека синхронизируются транзакционно через `PersonnelDirectory::updateOwn`. Маршрут всегда определяет пользователя из сессии/Sanctum, ID от клиента не принимается.
- Роль, активность, email-логин, табельный, колонна, instructor, увольнение и исходный снимок защищены от самоизменения (в том числе при административной роли). `account.profile_updated` пишет только имена изменённых полей. Полное ФИО расширено до 255 символов отдельной добавочной миграцией.
- Мобильный редактор `ProfileEditor` использует метаданные того же API, сохраняет ввод при сетевой ошибке, не хранит кадровые черновики на диске. Android 1.0.11 / versionCode 12. Новые нативные зависимости не добавлены.
- Логины `TABNOM@mail.ru` и случайные пароли подготовлены только для 262 активных импортированных технических аккаунтов. По прямому указанию владельца 364 архивных аккаунта исключены. Действующие реальные аккаунты не переоформляются; роли сохраняются для дальнейшего ручного назначения. Это идентификаторы входа, почтовые ящики не создаются и письма не отправляются. Пароли в открытом виде — только приватный CSV на компьютере владельца, на сервер передаются хеши bcrypt cost 12.
- Проверки: `SelfProfileTest` (веб + реальные Sanctum-токены, запрет повышения прав, чужого ID, неактивных, откат), `PersonnelDirectoryTest`, тесты маршрутов/доступа/журнала/учёта и безопасности.

Journal task mutations: TaskEditor + journal/mutations.cjs, shared JournalTasks service. Persist exact UUID/version/body before sending; manual retries survive restarts. Keep TextInput defaultValue stable while saving asynchronously. Reauthenticate preserves editor, explicit logout clears journal-tasks storage. No package/App/version changes from parallel profile development are included here. See JOURNAL_TASKS.md.

Shift adjustments (подстройки): driver, instructor, admin and super_admin. Dispatchers stay out of self-submit. `PodstroikiScreen` from Naryads (header + list card) and Profile. Sanctum `GET/POST /podstroiki`, `GET /podstroiki/limits`, `DELETE /podstroiki/{id}`. Structured `target_date` / `route_code` / `shift_code` / `details` plus frozen `operation_id`. Instructors skip the monthly count cap; drivers use dispatcher `NaryadPodstroikaLimit` (default 1). Unique date, rest-interval warning, and cancel of pending stay. A cancelled row on the same date is reopened instead of inserting a second. Keep this out of ATZ `TrainingRequestsScreen` and dispatcher `/naryad`. Logout clears `podstroiki` storage. Tests: `MobilePodstroikiTest`, `PodstroikiReviewResolutionTest`, `mobile/tests/podstroiki-mutations.test.cjs`. Android 1.1.6 / versionCode 22.

Personal work time: ProfileStack → WorkTimeScreen, GET/POST/PUT/PATCH/DELETE `/worktime` on mobile API.
A calendar day is either a shift, a rest day, or an absence; saved v2 rows can be deleted.

Main APK self-update from 1.1.6: public `GET /install/android.json` then same-origin `/install/android`. Compare native versionCode, download without a bearer token, size-check, install via intent-launcher. Existing 1.1.5 installs do not contain this check. Do not add Expo Updates or FCM for this. Leave `ru.tch15.arm.monitor` on its private installer.
See ../PERSONAL_WORKTIME_UI.md. Shared pure model.mjs/controller.mjs are also imported by web;
inject platform React rather than importing mobile React from web. All payroll math is server-side.
Persist UUID/version/exact request before sending in private `personal-worktime` scope (API URL + user ID).
Manual retry after uncertain responses; 409 requires comparison/rebase, 422 permits correction.
Reauthenticate preserves drafts; explicit logout clears them and blocks late writes. All breaks paid.
Subtotal is incomplete, not salary payable. Published with web/API migrations in Android 1.0.12 (13);
see ../RELEASE_2026-10-27.md. Future APK releases must increase versionCode and retain the release certificate.


ATZ: TrainingRequestsScreen in instructor Journal stack; training-mutations.cjs persists draft/exact UUID operation in training-requests namespace, isolated by API URL/user. Manual retries only; explicit logout stops and clears, reauthentication preserves. Use existing server ATZ services via /journal/training-requests; do not treat planning as completion. Version 1.0.14/code15 published on production with web/API and both migrations on 31 October 2026. Physical-phone instructor acceptance remains pending. See ../ATZ_RELEASE_2026-10-31.md and ../PRODUCTION_DEPLOYMENT.md.

Monitor APK: same Expo project, `EXPO_PUBLIC_MONITOR_APP=1` for BOTH prebuild and Gradle bundle, package `ru.tch15.arm.monitor`. Default is main `ru.tch15.arm`. MonitorApp has separate SecureStore `monitor-token`, 12-hour read-only backend token, no server/Grafana secrets. Main profile offers private authenticated installation only for super_admin through MonitorInstaller. Use expo-intent-launcher with FileSystem content URI; never put bearer tokens in installer URLs. Config plugin preserves release signing from owner-private keystore.properties. Regenerate native config when switching variants and verify actual APK package/version/certificate; don't trust app.config alone.

Documentation index: [../docs/README.md](../docs/README.md). Mobile build and feature documentation: [../docs/mobile/README.md](../docs/mobile/README.md). Commands still run from the mobile source directory, not docs/mobile.

Formular: Profile → FormularScreen, driver/instructor only. Own submissions and instructor review of own column share FormularService with web. Persist draft and exact UUID/version/body in private namespace formular before sending; manual retries only, preserve on reauthentication, clear epoch on explicit logout. Never auto-rebase a 409. See ../docs/mobile/FORMULAR.md. Android 1.1.5/code21.

Owner testing access: explicitly expose new user-facing features to active super_admin with corresponding server authorization and tests. Formular now supports own submissions/global review for super_admin without a column; self-review remains blocked. Do not simulate another user's identity or bypass version/idempotency rules.

Instruction signatures: Documents → Росписи, also linked from Profile. One screen instance in DocumentsStack; shared protected document/quiz viewers. Active super_admin included through existing document roles. Private instruction-signatures pending storage before send; manual exact retry, preserve first signed_at on server, logout clears epoch. No automatic sign-on-open or quiz prerequisite. See ../docs/mobile/INSTRUCTION_SIGNATURES.md.

Document catalog folders (unpublished development): keep backward-compatible categories[].items; optional folder_path, document_number and publication_year. The shared documents/catalog.mjs builds visible branches from authorized items only and is imported by the web viewer too. No native dependencies. Do not treat folders as permissions or store file bytes/public MinIO URLs in the tree. Main APK 1.1.9 remains the published release until a separately verified installer is built.

Unpublished document revisions: revision_label is display metadata; every edition has its own ID and acknowledgement. Lists and signed document streams enforce current/withdrawn status on the server, including already issued URLs. Keep pending acknowledgement scoped to its exact original document ID; never silently redirect it to a newer edition. Native dependencies unchanged; published APK remains 1.1.9 until separately released.
