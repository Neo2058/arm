# ТЧ-15 — мобильное приложение

Нативное приложение (Android / iOS), **не браузер и не копия сайта**.  
Сервер: Laravel `/api/mobile` (Sanctum). Файлы только через signed URL + `FileProxyService`, без прямых ссылок на MinIO.

Адрес API **вшивается в бинарь на сборке**. На телефоне его не поменять — только новой сборкой.

Текущий общий выпуск: **1.0.13 (Android versionCode 14)**, 28 октября 2026.
В профиле доступны редактирование личных данных, Telegram и личный табель;
у инструктора в журнале — создание/изменение/выполнение/удаление личных задач.
Табель использует общий серверный расчёт, оплачиваемые перерывы, ставки по месяцам
и проверку отдыха. Добавлены периоды больничного/отпуска/учёбы без расчёта их оплаты.
Длинные PDF передаются в просмотрщик порциями и рисуются постранично с ограничением памяти.
Денежный итог пока частичный. [Отчёт исправлений](../MOBILE_FIXES_2026-10-27.md).

Исправление закрытия Android APK при запуске (1.0.1): [ANDROID_CRASH_FIX.md](ANDROID_CRASH_FIX.md).
Для Expo 57 используются `expo-video` и `expo-audio`; `expo-av` 16 вызывает native crash с RN 0.86.
Проверять новый APK установкой и запуском: одного Metro export недостаточно.

По умолчанию в установщике: `https://www.тч-15.рф/api/mobile`
(`eas.json` → `env.EXPO_PUBLIC_API_URL`, punycode `https://www.xn---15-7edz.xn--p1ai/api/mobile`).
TLS-сертификат production соответствует www; api-config.cjs исправляет старое значение без www ещё до запроса.

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

Публикация требует Python 3.8+, Java и Android SDK build-tools (`apksigner`, `aapt` через PATH
или `ANDROID_HOME`/`ANDROID_SDK_ROOT`; на macOS также ищется стандартный каталог SDK).
Для Java Android Studio можно задать `JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"`.
Проверяются подпись закреплённого release-сертификата, пакет, versionCode и отсутствие debug/testOnly.
Понижение версии и другой APK с тем же versionCode отклоняются. Идентичная повторная публикация безопасна.
Сначала проверяется временная копия, затем файл заменяется атомарно. Предыдущий APK сохраняется
в `installer-backups/` корня проекта (вне public), метаданные — `public/downloads/release-<sha256>.json`.
Поле publisher_git_commit обозначает checkout публикации, а не доказательство происхождения сборки.
Если инструментов нет, скрипт отказывает в публикации, а не пропускает проверку.
При аварийном завершении возможен `.publish-installers.lock`: сначала убедитесь, что публикация не работает,
затем разберите причину сбоя; автоматически удалять чужой lock нельзя.
Смена сертификата требует отдельного согласованного изменения закреплённого fingerprint и плана миграции.
Для изолированной проверки используется `--destination /tmp/arm-publish-check/public/downloads`.

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

Для IPA проверяется структура архива и bundle identifier, замена также атомарная.
Проверка Apple-подписи этим скриптом **не выполняется**; она остаётся отдельным шагом iOS-релиза.

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


## Личные профили и логины — 26 октября 2026

- `/account` GET/PATCH и `/api/mobile/account/profile` GET/PATCH используют один `SelfProfile` и рабочую карточку `arm_personnel`. Телефоны, личные и кадровые поля можно редактировать самостоятельно; профиль и картотека синхронизируются транзакционно через `PersonnelDirectory::updateOwn`. Маршрут всегда определяет пользователя из сессии/Sanctum, ID от клиента не принимается.
- Роль, активность, email-логин, табельный, колонна, instructor, увольнение и исходный снимок защищены от самоизменения (в том числе при административной роли). `account.profile_updated` пишет только имена изменённых полей. Полное ФИО расширено до 255 символов отдельной добавочной миграцией.
- Мобильный редактор `ProfileEditor` использует метаданные того же API, сохраняет ввод при сетевой ошибке, не хранит кадровые черновики на диске. Android 1.0.11 / versionCode 12. Новые нативные зависимости не добавлены.
- Логины `TABNOM@mail.ru` и случайные пароли подготовлены только для 262 активных импортированных технических аккаунтов. По прямому указанию владельца 364 архивных аккаунта исключены. Действующие реальные аккаунты не переоформляются; роли сохраняются для дальнейшего ручного назначения. Это идентификаторы входа, почтовые ящики не создаются и письма не отправляются. Пароли в открытом виде — только приватный CSV на компьютере владельца, на сервер передаются хеши bcrypt cost 12.
- Проверки: `SelfProfileTest` (веб + реальные Sanctum-токены, запрет повышения прав, чужого ID, неактивных, откат), `PersonnelDirectoryTest`, тесты маршрутов/доступа/журнала/учёта и безопасности.
