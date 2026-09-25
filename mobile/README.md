# ТЧ-15 — мобильное приложение

Нативное приложение (Android / iOS), **не браузер и не копия сайта**.  
Сервер: Laravel `/api/mobile` (Sanctum). Файлы только через signed URL + `FileProxyService`, без прямых ссылок на MinIO.

Адрес API **вшивается в бинарь на сборке**. На телефоне его не поменять — только новой сборкой.

По умолчанию в установщике: `https://тч-15.рф/api/mobile`  
(`eas.json` → `env.EXPO_PUBLIC_API_URL`, punycode `https://xn---15-7edz.xn--p1ai/api/mobile`).

---

## Что умеет (прототип)

Вход тем же email/паролем, что на сайте. Админки Filament нет.

| Есть | Нет (намеренно) |
|---|---|
| Логин / выход, профиль (ФИО, роль) | Журнал ТЧМ |
| Документы по `allowed_roles`, просмотр PDF | Сетка наряда, нарядчик |
| Темы обучения → видео / аудио / текст | Росписи, квизы, подстройки |
| Комментарии к материалам | Учёт / ЛС (`/uchet`) |
| | Привязка устройства и барьер |
| | Play Store / App Store |
| | Офлайн-кэш, пуши |

Роли: любой активный пользователь может войти и читать. Кнопок «загрузить документ», «админка», «наряд» нет.

---

## Как скомпилировать установщик

Android Studio **не обязателен**. Нужен аккаунт [expo.dev](https://expo.dev).

```bash
cd mobile
npm install
npx eas-cli login
npx eas-cli init          # один раз: привязка к Expo-проекту, появится projectId
```

### Android → APK (раздача сотрудникам)

```bash
npm run build:android
# то же: npx eas-cli build --platform android --profile preview
```

Профиль `preview` в `eas.json` собирает **APK** (не AAB). Play Store не нужен.  
Готовый файл скачивается из интерфейса Expo или:

```bash
npx eas-cli build:download --latest --platform android
```

Положить на сайт:

```bash
bash scripts/publish-installers.sh ~/Downloads/*.apk
# → public/downloads/tch15-android.apk
```

Сотрудник открывает `https://тч-15.рф/install` → «Скачать APK» → разрешить установку из этого источника.

Локальная сборка через Android Studio возможна (`npx expo prebuild -p android`), но для прототипа не нужна.

### iOS → IPA

Xcode соберёт приложение **у вас на Маке**. IPA для чужих iPhone — только с **Apple Developer** (~99 $/год): сертификат + ad-hoc/enterprise профиль.

```bash
npm run build:ios
# то же: npx eas-cli build --platform ios --profile preview
```

Положить IPA:

```bash
bash scripts/publish-installers.sh ~/Downloads/*.ipa
# → public/downloads/tch15-ios.ipa
```

На iPhone кнопка `/install` открывает системный установщик (`itms-services`), не сайт в Safari.

Без Developer Program:

- симулятор: `npx expo prebuild -p ios` и Run в Xcode;
- свой iPhone по кабелю с бесплатным Apple ID — на ~7 дней, не для раздачи.

Профиль `preview-sim` в `eas.json` — сборка под симулятор, не для установки на устройство.

### Сменить сервер

Правится **до** сборки:

- `mobile/eas.json` → `build.preview.env.EXPO_PUBLIC_API_URL`
- и Laravel `APP_URL` (тот же хост, иначе signed PDF/видео не откроются)

Потом новая сборка APK/IPA.

---

## Разработка без установщика (Expo Go)

```bash
# корень репозитория
php artisan serve --host=0.0.0.0 --port=8000
php artisan migrate   # таблица personal_access_tokens

cd mobile
EXPO_PUBLIC_API_URL=http://127.0.0.1:8000/api/mobile npx expo start
```

| Где крутится приложение | `EXPO_PUBLIC_API_URL` |
|---|---|
| iOS Simulator | `http://127.0.0.1:8000/api/mobile` |
| Android Emulator | `http://10.0.2.2:8000/api/mobile` |
| Физический телефон в Wi‑Fi | `http://<IP-Мака>:8000/api/mobile` |

Это **не** то, что ставят сотрудники. Для раздачи — только APK/IPA выше.

---

## API (для справки)

| Метод | Путь | Auth |
|---|---|---|
| POST | `/api/mobile/login` | нет |
| GET/POST | `/api/mobile/me`, `/logout` | Bearer |
| GET | `/api/mobile/documents`, `/documents/{id}` | Bearer |
| GET | `/api/mobile/training/topics`, `.../topics/{slug}`, `.../materials/{id}` | Bearer |
| POST | `/api/mobile/training/materials/{id}/comments` | Bearer |
| GET | `/api/mobile/files/documents/{id}`, `/files/training/{id}` | signed URL, без сессии |

Код клиента: `mobile/src/` (`App.js`, экраны логина, документов, обучения, плеера и комментариев).
