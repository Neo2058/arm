# ТЧ-15 mobile (прототип)

Expo / React Native: документы, обучение (видео/аудио), комментарии. Без админки.

## API

Бэкенд: `POST/GET /api/mobile/...` (Sanctum). Файлы отдаются signed URL через `FileProxyService`, без прямых ссылок на MinIO.

Базовый URL задаётся в `app.json` → `expo.extra.apiUrl`.

- Симулятор iOS: `http://127.0.0.1:8000/api/mobile`
- Эмулятор Android: `http://10.0.2.2:8000/api/mobile`
- Физическое устройство: `http://<IP-машины>:8000/api/mobile` и `APP_URL` Laravel должен быть тем же хостом (иначе signed URL не откроется).

## Запуск

```bash
# в корне репозитория
php artisan serve --host=0.0.0.0 --port=8000

cd mobile
npx expo start
```

Дальше Expo Go на телефоне или iOS/Android симулятор.

Вход — те же email/пароль, что на сайте.
