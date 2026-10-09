# Обновление зависимостей — этап 4

Проверка: 2026-10-09. Production не изменён. Новые версии зафиксированы в lock-файлах.

## Результат аудита

| Набор | До | После | Статус |
|---|---|---|---|
| PHP (`composer audit --locked`) | 18 пакетов с advisories, включая runtime и dev | 0 advisories | Исправления установлены и проверены локально |
| Web (`npm audit --package-lock-only`) | 12 затронутых пакетов | 0 | Исправления установлены; production build прошёл |
| Mobile (`npm audit --package-lock-only`) | 25 затронутых пакетов, 1 critical | 22: 15 high, 7 moderate, 0 critical | Частичное исправление; остаток открыт |

Количество затронутых пакетов npm включает родительские пакеты, получившие severity через транзитивную
зависимость: 22 mobile packages не означают 22 независимых уязвимости. Audit не доказывает достижимость
каждой уязвимости из пользовательского запроса и не гарантирует отсутствие неизвестных уязвимостей.

## Основные версии

| Пакет | Было | Стало |
|---|---|---|
| Laravel | 12.58.0 | 12.69.3 |
| Filament (все компоненты) | 3.3.50 | 3.3.56 |
| Livewire | 3.8.0 | 3.8.10 |
| Guzzle | 7.10.0 | 7.15.5 |
| guzzlehttp/psr7 | 2.9.0 | 2.13.1 |
| league/commonmark | 2.8.2 | 2.10.3 |
| league/flysystem | 3.33.0 | 3.36.0 |
| mtdowling/jmespath.php | 2.8.0 | 2.9.2 |
| Symfony security components | 7.4.8–7.4.9 | 7.4.19–7.4.20 |
| Web Axios | 1.16.0 | 1.20.0 |
| Vite | 7.3.2 | 7.3.7 |
| Expo | 57.0.24 | 57.0.27 |
| shell-quote (web / mobile) | 1.8.3 / 1.10.0 | 1.12.0 |
| source-map-js | 1.2.1 | 1.2.2 |

PHP: обновлены 24 записи lock, с --minimal-changes и platform.php=8.2.0.
Web/mobile: разрешение внутри существующих диапазонов package.json, без смены Expo SDK, React Native
или основных веток Laravel/Filament. Дополнительно обновились совместимые frontend/build транзитивные пакеты;
список точных версий — в diff lock-файлов. platform не заменяет проверку в настоящем контейнере PHP 8.2.

## Оставшиеся mobile advisories

1. **braces 3.0.3**, [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm):
   переполнение стека при глубоко вложенных шаблонах. На дату проверки исправленного релиза нет
   (npm registry: latest=3.0.3). Приходит через micromatch/Metro/Expo tooling.
2. **node-forge 1.4.0**, [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv):
   проверка RSA PKCS#1 v1.5 подписи. На дату проверки npm latest=1.4.0, версия затронута advisory.
   Приходит через Expo CLI и @expo/code-signing-certificates. Не считать проверку сертификатов безопасной
   только потому, что основной web/backend audit чистый.
3. **uuid 7.0.3**, [GHSA-w5hq-g745-h8pq](https://github.com/advisories/GHSA-w5hq-g745-h8pq):
   отсутствует проверка границ переданного буфера в v3/v5/v6. Приходит через xcode 3.0.1 (^7.0.3).
   В прочитанном xcode/lib/pbxProject.js используется v4 без буфера: этот конкретный вызов не соответствует
   условиям advisory. Подмена зависимости на uuid 11 требует отдельной проверки совместимости, не сделана.

Это открытый остаток, не исключения audit и не автоматическое принятие риска. Не открывать Expo/Metro dev server
публично и не обрабатывать недоверенные glob-шаблоны или сертификаты через tooling; эти ограничения уменьшают
поверхность атаки, но не исправляют библиотеки. Перепроверить upstream перед сборкой/публикацией mobile.
`npm audit fix --force` предлагает несовместимые откаты Expo/RN; они не применялись.

## Выполненные проверки

- `composer validate --strict --no-check-publish`: успешно.
- `composer install --no-scripts --no-interaction`: обновления реально установлены локально.
- `vendor/bin/phpunit`: **211 tests, 944 assertions**, включая доступ, файлы, CSRF/Livewire и квизы.
- Web `npm ci --ignore-scripts --no-audit --no-fund`, затем `npm run build`: успешно.
- Mobile `npm ci --ignore-scripts --no-audit --no-fund`: успешно.
- Mobile offline Expo export Android + iOS без bytecode: успешно, JS bundles в `/tmp/arm-mobile-security-export`.
  Это проверка Metro/JS, не native APK/IPA и не smoke на устройстве.
- Offline `expo install --check` сообщил up to date, но сам предупредил о ненадёжности offline validation;
  не использовать этот результат как единственное подтверждение SDK-совместимости.
- Отдельно сопоставлены версии установленных native packages с bundledNativeModules.json из Expo 57.0.27:
  React, RN, Constants, Linking, SecureStore, StatusBar, SafeAreaContext, Screens и WebView соответствуют диапазонам.
- `composer install --dry-run --no-dev --no-scripts --no-interaction`: успешное разрешение production набора.
- Повторные PHP/web audits: 0; mobile residual описан выше.

Локальная среда PHP 8.5.7 / Node 25.8.0. Browser smoke Filament и реальное выполнение на PHP 8.2/Linux
ещё нужны. Composer scripts локально не запускались для публикации assets; на release их нужно выполнить.

## Ручная выкладка без CI/CD

1. Сохранить previous SHA, backup БД и защищённую конфигурацию. Подготовить отдельный checkout нового release.
2. В контейнере с production PHP выполнить `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
   по composer.lock. Не выполнять composer update на production и не использовать --ignore-platform-reqs.
3. Проверить `composer check-platform-reqs --no-dev`; он проверяет реальную среду, независимо от platform.php.
4. Установка с scripts должна выполнить package discovery/Filament upgrade; проверить публикацию
   Filament/Livewire assets и корректность прав. При намеренном --no-scripts выполнить release scripts отдельно.
5. Web: `npm ci` и `npm run build` в build-среде; Node >=20.19 либо >=22.12 для Vite 7,
   актуальный поддерживаемый Node для сборки. Не запускать Vite dev server на production.
6. В maintenance обновить исходники, vendor, public/build и опубликованные Filament/Livewire assets
   как один release. Сохранить предыдущие assets для отката. Миграции в этом этапе не добавлялись.
7. Очистить кеш, перезапустить app/workers, восстановить кеш после перезапуска (entrypoint очищает его), снять maintenance.
8. Smoke: login/logout Filament, создание/редактирование формы, upload PDF, preview, оба квиза,
   документы, журнал, наряд и mobile API. Особое внимание Livewire upload: теперь используется относительная подпись URL.
9. Mobile lock не обновляет уже установленные APK/IPA. Новый native build и раздача — отдельная операция
   после разбора открытого residual, проверки API URL и smoke на Android/iOS. EAS/build/publish не выполнялись.
10. Откат: вернуть согласованный набор previous source/vendor/build/published assets, очистить кеш,
    перезапустить процессы и выполнить smoke. Старые версии возвращают известные уязвимости — учитывать это при откате.

После публикации повторить аудиты и записать SHA/дату/smoke в SECURITY_ROADMAP.md.
