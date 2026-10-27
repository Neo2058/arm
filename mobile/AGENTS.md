# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

Playback uses expo-video/expo-audio matching SDK 57, not expo-av 16 (native JSI crash on RN 0.86).
Keep expo-asset in the SDK 57 range: unconstrained peer installation can pull SDK 58.
Document reading uses PDF.js inside WebView from fetched bytes — never `WebView source={{ uri: pdfUrl }}` (Android DownloadManager). `expo-screen-capture` FLAG_SECURE + screenshot listener; capture attempts POST `/documents/{id}/violation`. Do not list `expo-screen-capture` in `app.config.js` plugins: the package has no `app.plugin.js` and prebuild then loads ESM `build/ScreenCapture.js`. Native code is autolinked from the dependency.
Tabs: documents, quizzes (`QuizService` on the server), naryads (personal TXT + in-app PDF), training, profile.
Shared phone book is `GET /phones` (paginated) and `POST /phones`, backed by PostgreSQL phone_contacts.
All active users may add contacts; name and normalized unique number are shared with web users.
Keep form values on network errors; duplicate retries must not create another record.
Native dependency changes require a new APK and an install/start smoke test; JS export alone is insufficient.
Preserve local native signing configuration when regenerating ignored android/ios directories.


Discipline: instructor-only tab, shared Laravel DisciplineService behind Sanctum routes. See DISCIPLINE.md. Keep one frozen operation UUID, version, response and file list on uncertain network errors; never silently replace the request on retry. Persistent drafts and frozen operations use the private FileSystem journal (storage.js), scoped to API URL + user ID; queue.cjs is tested with injected storage. Persist before sending, keep UUID/version/files across restarts. Automatic retries run only while active; auth/conflict failures block. Reauthenticate preserves drafts, logout deletes current scope. See DISCIPLINE.md. Files use token-authenticated byte requests and in-app viewing, not public MinIO URLs. DocumentPicker/Crypto match SDK 57 and require a new native binary.

Journal read views: JournalScreen exports overview/tasks/crew, reached from DisciplineList without adding another bottom tab. Token-only GET /journal{,/tasks,/crew}; no long-lived journal cache or mutations. See JOURNAL.md; keep journal tasks distinct from discipline submissions.

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

Personal work time: ProfileStack → WorkTimeScreen, GET/POST/PUT/PATCH `/worktime` on mobile API.
See ../PERSONAL_WORKTIME_UI.md. Shared pure model.mjs/controller.mjs are also imported by web;
inject platform React rather than importing mobile React from web. All payroll math is server-side.
Persist UUID/version/exact request before sending in private `personal-worktime` scope (API URL + user ID).
Manual retry after uncertain responses; 409 requires comparison/rebase, 422 permits correction.
Reauthenticate preserves drafts; explicit logout clears them and blocks late writes. All breaks paid.
Subtotal is incomplete, not salary payable. Local worktime APK is not published; release must include API migrations.
