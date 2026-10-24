# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

Playback uses expo-video/expo-audio matching SDK 57, not expo-av 16 (native JSI crash on RN 0.86).
Keep expo-asset in the SDK 57 range: unconstrained peer installation can pull SDK 58.
Document reading uses PDF.js inside WebView from fetched bytes — never `WebView source={{ uri: pdfUrl }}` (Android DownloadManager). `expo-screen-capture` FLAG_SECURE + screenshot listener; capture attempts POST `/documents/{id}/violation`. Do not list `expo-screen-capture` in `app.config.js` plugins: the package has no `app.plugin.js` and prebuild then loads ESM `build/ScreenCapture.js`. Native code is autolinked from the dependency.
Tabs: documents, quizzes (`QuizService` on the server), naryads (personal TXT + in-app PDF), training, profile. Phone book is `GET /phones` from `arm_personnel`.
Native dependency changes require a new APK and an install/start smoke test; JS export alone is insufficient.
Preserve local native signing configuration when regenerating ignored android/ios directories.


Discipline: instructor-only tab, shared Laravel DisciplineService behind Sanctum routes. See DISCIPLINE.md. Keep one frozen operation UUID, version, response and file list on uncertain network errors; never silently replace the request on retry. The current implementation keeps it in memory only; persistent drafts/offline queue are a separate stage. Files use token-authenticated byte requests and in-app viewing, not public MinIO URLs. DocumentPicker/Crypto match SDK 57 and require a new native binary.
