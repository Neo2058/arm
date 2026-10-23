# Закрытие Android APK сразу после запуска

Дата: 2026-10-23. Проверяется APK `public/downloads/tch15-android.apk`, установленный со страницы `/install`.

## Подтверждённая причина

Старый APK 1.0.0 (versionCode 1) установлен в arm64 эмулятор Pixel 7, Android API 37, 16 KiB pages.
Запуск воспроизвёл падение до экрана входа. Android crash log:

```text
java.lang.UnsatisfiedLinkError: dlopen failed: cannot locate symbol
"_ZNKR8facebook3jsi5Value8asObjectERNS0_7RuntimeE"
referenced by ".../lib/arm64-v8a/libexpo-av.so"
at expo.modules.av.AVManager.<clinit>
```

`expo-av` 16 несовместим с текущей нативной связкой Expo 57 / React Native 0.86.3.
Нативные модули загружаются при старте: отсутствие открытия экрана с видео не предотвращает падение.
Ошибка системная, поэтому пользователь может видеть только закрытие приложения без сообщения.

Предыдущая проверка Metro export этого не выявляла: успешная JS-сборка не проверяет загрузку `.so` в Android.

## Исправления

- Удалён expo-av, его импорт и config plugin.
- Видео использует expo-video 57.0.5, аудио — expo-audio 57.0.5; версии соответствуют Expo SDK 57.
- expo-asset явно зафиксирован на 57.0.19 из набора SDK 57: npm первоначально выбрал peer dependency
  58.0.18, у которой отсутствовало поле publication.version для текущего autolinking.
- Ошибка чтения SecureStore при запуске теперь обрабатывается вместе с восстановлением сессии;
  finally снимает индикатор загрузки. Это отдельное улучшение, не причина нативного падения выше.
- Версия приложения 1.0.1, Android versionCode 2; iOS buildNumber 2 для последующей сборки.
- Изменения пользователя в scripts.android/ios (`expo run:*`) сохранены.

Использованы [expo-video SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/video/)
и [expo-audio SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/audio/).
Плееры освобождают ресурсы через lifecycle hooks; signed URL сервера и API авторизации не менялись.

## План проверки

- [x] Воспроизвести падение настоящего раздаваемого APK.
- [x] Удалить несовместимый native module и обновить исходники/lock.
- [x] Проверить JS export Android и iOS.
- [x] Собрать новый native release APK.
- [x] Установить поверх старого в эмуляторе, проверить экран входа, отсутствие crash и повторный запуск.
- [x] Сравнить сертификаты подписи, проверить отсутствие libexpo-av.so в APK.
- [x] Собрать обе исходные ABI arm64-v8a и armeabi-v7a для раздачи.
- [ ] Проверить на реальных устройствах вход, PDF и воспроизведение защищённых видео/аудио.

## Выкладка

Это native-изменение: обновления JS или перезапуска Laravel недостаточно. Нужен новый APK и его установка.
Не выполнять `expo prebuild --clean` поверх существующего локального android без сохранения настроек подписи:
там уже есть пользовательская release signing configuration.

Перед заменой раздаваемого файла сохранить предыдущий APK и сверить сертификат. Версия с другим сертификатом
не установится поверх старой; удалять приложение сотрудникам для обхода подписи не предлагается.
Сборку раздачи выполнять для прежних ABI, а не только для архитектуры эмулятора.
Новый APK публиковать после smoke на устройстве через существующий `scripts/publish-installers.sh`;
эта публикация должна быть отдельным подтверждённым действием.

## Готовый артефакт и результаты

Ниже результаты сборки 1.0.1. Текущий локальный APK по этому пути пересобран в 1.0.2;
его актуальный хеш и ограничения проверки описаны в `LOGIN_FIX.md`.

- APK: `mobile/android/app/build/outputs/apk/release/app-release.apk` (локальный ignored build output).
- Пакет `ru.tch15.arm`, версия 1.0.1, versionCode 2, minSdk 24, ABI arm64-v8a + armeabi-v7a.
- SHA-256 APK: `6904ed590725d18fc149d491372086fa5fc84bdb747c79c92f536fe3ecb04197`.
- Сертификат совпадает со старым APK: `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`.
- Gradle `:app:assembleRelease`: успешно для обеих ABI. Подпись проверена apksigner.
- Итоговый универсальный APK установлен поверх старого; cold start успешен, Activity ru.tch15.arm/.MainActivity.
- UIAutomator подтвердил форму «Вход для сотрудников», поля Email/Пароль и кнопку «Войти».
- Новый crash-буфер пуст после запуска исправленной версии. Старый crash сохранён в `/tmp/arm-android-original-crash.log`.
- Metro JS export Android/iOS: успешно; проверка Expo по локальному bundledNativeModules: версии соответствуют.
- Установка на физические телефоны, вход с реальной учётной записью, PDF/медиа и native iOS пока не проверены.
- `public/downloads/tch15-android.apk` и production не заменялись. Обычный git pull не переносит APK:
  native output игнорируется git, для `/install` требуется отдельно опубликовать новый установщик.
