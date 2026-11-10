# Мобильный вход 1.1.3 — 10.11.2026

После 1.1.2 телефон не доходил до `POST /api/mobile/login` и `GET /api/mobile/me`. PHP login на www отвечал, веб-вход работал. Исправление клиентское: APK 1.1.3 / versionCode 19.

## Клиент

- `POST /login` не ждёт SecureStore и не подставляет старый Bearer.
- Чтение/запись токена ограничены 2 с; зависание хранилища больше не держит экран загрузки.
- Кэш `disciplineIdentity` не блокирует вход.
- Шапка `X-TCH15-Client`.
- Форма входа в SafeArea.
- ABI снова `armeabi-v7a,arm64-v8a` (без x86/x86_64).

## Android

- Пакет `ru.tch15.arm`, 1.1.3 / versionCode 19.
- SHA-256 `dbe226f10c2801650f33707fa4291e96cca50f78d7bcb97d2f30c6f5771de4db`.
- Сертификат выпуска `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`.
- Предыдущий live 1.1.2 SHA `3c5d4e69286e810c5145b1722673b062c53d44748900470525e5512833e16bd2`.

Установить поверх 1.1.2 с `/install`. Понижать versionCode нельзя.
