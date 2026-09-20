# ТЧ-15 mobile

Нативное приложение (не браузер): документы, обучение, комментарии.
Адрес API **вшивается на сборке** — в установщике уже прописан сервер `https://тч-15.рф/api/mobile`.

## Установка сотрудникам

1. Собрать APK / IPA (ниже).
2. Положить файлы:
   - `public/downloads/tch15-android.apk`
   - `public/downloads/tch15-ios.ipa`
3. Открыть на телефоне `https://тч-15.рф/install` и нажать кнопку своей ОС.

Или одной командой после скачивания артефакта EAS:

```bash
cd mobile
npx eas build:download --latest --platform android   # путь покажет CLI
bash scripts/publish-installers.sh ~/Downloads/*.apk
```

### Android
APK ставится напрямую (разрешить «неизвестные источники» для Chrome/Файлов). Play Store не нужен.

### iOS
Нужна учётка Apple Developer. Профиль `preview` в `eas.json` — internal/ad-hoc. На iPhone кнопка «Установить» открывает системный установщик (`itms-services`), не сайт.

## Сборка установщиков

Нужен аккаунт Expo: https://expo.dev

```bash
cd mobile
npx eas-cli login
npx eas-cli init          # один раз, сохранит projectId
npm run build:android     # APK, internal
npm run build:ios         # IPA, internal
```

Адрес сервера задаётся в `eas.json` → `env.EXPO_PUBLIC_API_URL` и попадает в бинарь. Менять «на телефоне» нельзя — только новой сборкой.

Локальный API для разработки (Expo Go, не установщик):

```bash
EXPO_PUBLIC_API_URL=http://127.0.0.1:8000/api/mobile npx expo start
```

Android-эмулятор: `http://10.0.2.2:8000/api/mobile`.
На устройстве в LAN Laravel `APP_URL` должен совпадать с хостом в `EXPO_PUBLIC_API_URL` (signed URL на PDF/видео).
