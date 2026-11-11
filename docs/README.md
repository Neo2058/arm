# Документация АРМ ТЧ-15

Единая точка входа в документацию проекта. Структура упорядочена 10 ноября 2026 года; дата переноса не является датой повторной проверки всех эксплуатационных утверждений.

## Быстрый поиск

| Задача | Документ |
| --- | --- |
| Понять назначение и посмотреть интерфейс | [README проекта](../README.md) |
| Разобраться в архитектуре | [Архитектура](architecture/ARCHITECTURE.md) |
| Обновить production | [Штатная выкладка](operations/PRODUCTION_DEPLOYMENT.md), [защищённый runtime](operations/SERVER_HARDENING_RUNBOOK.md) |
| Проверить состояние переноса учёта | [Схема АРМ-ЛБ](architecture/armd.md), [очередь работ](roadmap/armd-progress.md) |
| Найти долг и планы | [Технический долг](roadmap/TECHNICAL_DEBT.md), [планы безопасности](roadmap/SECURITY_ROADMAP.md) |
| Собрать Android | [Мобильное приложение](mobile/README.md), [последний описанный выпуск 1.1.4](releases/mobile-1.1.4.md) |
| Проверить нагрузочную готовность | [Отчёт о 500 одновременных пользователях](operations/capacity/2026-11-09/READINESS_500.md) |
| Найти мониторинг и резервирование | [Мониторинг](operations/MONITORING_RUNBOOK.md), [копии на ПК](roadmap/PC_BACKUP_PLAN.md) |
| Подготовить представление проекта | [Дизайн-документ](management/ARM_DESIGN_DOCUMENT.md), [презентация](management/presentation/README.md), [видео](management/video/README.md) |

## Как читать статусы

- **Инструкция / описание** — документ для работы с соответствующим модулем; подробности и ограничения читать внутри.
- **План / очередь** — намерения и этапы, наличие документа не означает завершения работ.
- **Аудит / исторический отчёт** — снимок на указанную дату, а не гарантия текущего состояния production.
- Датированные отчёты и исходные материалы сохранены. При противоречиях не применять старые команды автоматически: сначала сверить профильную инструкцию, последний выпуск и фактическую конфигурацию.
- [Замеченные расхождения и правила ведения](DOCUMENTATION_GUIDE.md) помогают отличать архив от текущих сведений.

## Разделы

- [Архитектура](#архитектура)
- [Функциональные модули](#функциональные-модули)
- [Мобильное приложение](#мобильное-приложение)
- [Безопасность](#безопасность)
- [Эксплуатация](#эксплуатация)
- [Планы развития и технический долг](#планы-развития-и-технический-долг)
- [История выпусков](#история-выпусков)
- [Материалы для руководства](#материалы-для-руководства)

## Архитектура

| Документ | Назначение | Тип |
| --- | --- | --- |
| [architecture/ARCHITECTURE.md](architecture/ARCHITECTURE.md) | Architecture Overview — ТЧ-15 (ARM) | Инструкция / описание |
| [architecture/TwoSideApi.md](architecture/TwoSideApi.md) | Исходная постановка двустороннего обмена | Исходные требования |
| [architecture/armd.md](architecture/armd.md) | Клон АРМ-ЛБ (FoxPro → ТЧ-15) | Инструкция / описание |

## Функциональные модули

| Документ | Назначение | Тип |
| --- | --- | --- |
| [modules/DISCIPLINE.md](modules/DISCIPLINE.md) | Исполнительская дисциплина | Инструкция / описание |
| [modules/INSTRUCTOR_ATZ.md](modules/INSTRUCTOR_ATZ.md) | Подстройки и учёт АТЗ | Инструкция / описание |
| [modules/PERSONAL_WORKTIME_API.md](modules/PERSONAL_WORKTIME_API.md) | Общий API личного табеля: смены и настройки | Инструкция / описание |
| [modules/PERSONAL_WORKTIME_BACKEND.md](modules/PERSONAL_WORKTIME_BACKEND.md) | Серверная основа личного табеля, версия 2 | Инструкция / описание |
| [modules/PERSONAL_WORKTIME_MONTH.md](modules/PERSONAL_WORKTIME_MONTH.md) | Ночные связки и месячная сводка личного табеля | Инструкция / описание |
| [modules/PERSONAL_WORKTIME_UI.md](modules/PERSONAL_WORKTIME_UI.md) | Личный табель: подключение интерфейсов | Инструкция / описание |
| [modules/WORK_TIME_RULES_COMPARISON.md](modules/WORK_TIME_RULES_COMPARISON.md) | Сверка учёта смен: Laravel / ARMNRD | Инструкция / описание |

## Мобильное приложение

| Документ | Назначение | Тип |
| --- | --- | --- |
| [mobile/ANDROID_CRASH_FIX.md](mobile/ANDROID_CRASH_FIX.md) | Закрытие Android APK сразу после запуска | История исправления |
| [mobile/DISCIPLINE.md](mobile/DISCIPLINE.md) | Поручения руководителя в мобильном приложении | Инструкция / описание |
| [mobile/ICONS.md](mobile/ICONS.md) | Иконки — версия 1.0.8 | История исправления |
| [mobile/JOURNAL.md](mobile/JOURNAL.md) | Мобильный журнал инструктора — Android 1.0.7 | Инструкция / описание |
| [mobile/JOURNAL_TASKS.md](mobile/JOURNAL_TASKS.md) | Личные задачи журнала — доработка 27 октября 2026 | Инструкция / описание |
| [mobile/LOGIN_FIX.md](mobile/LOGIN_FIX.md) | Ошибка входа Android: TLS и учётные данные | История исправления |
| [mobile/README.md](mobile/README.md) | ТЧ-15 — мобильное приложение | Инструкция / описание |

## Безопасность

| Документ | Назначение | Тип |
| --- | --- | --- |
| [security/APK_SECURITY_AUDIT_2026-10-24.md](security/APK_SECURITY_AUDIT_2026-10-24.md) | Аудит мобильного приложения и публикации APK | Аудит / исторический отчёт |
| [security/BACKUP_AUDIT_2026-10-30.md](security/BACKUP_AUDIT_2026-10-30.md) | Резервные копии ARM — аудит и тест 30 октября 2026 | Аудит / исторический отчёт |
| [security/CLICKHOUSE_STORAGE_AUDIT_2026-10-28.md](security/CLICKHOUSE_STORAGE_AUDIT_2026-10-28.md) | Объём ClickHouse — 28 октября 2026 | Аудит / исторический отчёт |
| [security/SECURITY_CHECKPOINT_2026-10-28.md](security/SECURITY_CHECKPOINT_2026-10-28.md) | Точка остановки и проверка PHP2Shell — 28 октября 2026 | Аудит / исторический отчёт |
| [security/SECURITY_DEPENDENCIES.md](security/SECURITY_DEPENDENCIES.md) | Обновление зависимостей — этап 4 | Аудит / исторический отчёт |
| [security/SERVER_HARDENING_PROGRESS_2026-10-28.md](security/SERVER_HARDENING_PROGRESS_2026-10-28.md) | Укрепление сервера — состояние 28 октября 2026 | Аудит / исторический отчёт |
| [security/SERVER_NETWORK_AUDIT_2026-10-29.md](security/SERVER_NETWORK_AUDIT_2026-10-29.md) | Сетевая граница production — 29 октября 2026 | Аудит / исторический отчёт |
| [security/SERVER_SECURITY_AUDIT_2026-10-28.md](security/SERVER_SECURITY_AUDIT_2026-10-28.md) | Аудит границ доступа и план обнаружения атак — 28 октября 2026 | Аудит / исторический отчёт |

## Эксплуатация

| Документ | Назначение | Тип |
| --- | --- | --- |
| [operations/DEPLOYMENT.md](operations/DEPLOYMENT.md) | docs/operations/DEPLOYMENT.md | Инструкция / описание |
| [operations/EVENT_HISTORY_RUNBOOK.md](operations/EVENT_HISTORY_RUNBOOK.md) | История событий: очистка и ручной архив | Инструкция / описание |
| [operations/MONITORING_RUNBOOK.md](operations/MONITORING_RUNBOOK.md) | ARM: закрытый мониторинг сервера и приложения | Инструкция / описание |
| [operations/PRODUCTION_DEPLOYMENT.md](operations/PRODUCTION_DEPLOYMENT.md) | Обновление production после усиления защиты | Инструкция / описание |
| [operations/SERVER_HARDENING_RUNBOOK.md](operations/SERVER_HARDENING_RUNBOOK.md) | Эксплуатация изолированного runtime ARM | Инструкция / описание |
| [operations/TELEGRAM_SETUP.md](operations/TELEGRAM_SETUP.md) | Два Telegram-бота ARM | Инструкция / описание |
| [operations/capacity/2026-11-09/ACTIVE_TEST.md](operations/capacity/2026-11-09/ACTIVE_TEST.md) | Ограниченный тест чтения production | Отчёт измерений |
| [operations/capacity/2026-11-09/CONCURRENT_TEST.md](operations/capacity/2026-11-09/CONCURRENT_TEST.md) | Продолжение: параллельное чтение | Отчёт измерений |
| [operations/capacity/2026-11-09/READINESS_500.md](operations/capacity/2026-11-09/READINESS_500.md) | Проверка допуска 500 одновременно работающих сотрудников | Отчёт измерений |
| [operations/capacity/2026-11-09/REPORT.md](operations/capacity/2026-11-09/REPORT.md) | Production: исходные измерения ёмкости | Отчёт измерений |
| [operations/capacity/2026-11-09/RESULTS.md](operations/capacity/2026-11-09/RESULTS.md) | Результат теста production — 09.11.2026 | Отчёт измерений |

## Планы развития и технический долг

| Документ | Назначение | Тип |
| --- | --- | --- |
| [roadmap/BACKUP_STORAGE_OPTIONS.md](roadmap/BACKUP_STORAGE_OPTIONS.md) | Внешние резервные копии ARM: варианты и требования | План / очередь |
| [roadmap/CI_CD_PLAN.md](roadmap/CI_CD_PLAN.md) | План развития: безопасный CI/CD ARM | План / очередь |
| [roadmap/INTERNAL_CHAT_PLAN.md](roadmap/INTERNAL_CHAT_PLAN.md) | План развития внутреннего чата ARM | План / очередь |
| [roadmap/MOBILE_WEB_PARITY_PLAN.md](roadmap/MOBILE_WEB_PARITY_PLAN.md) | Разделы веба в мобильном приложении и учёт рабочего времени | План / очередь |
| [roadmap/MONITOR_MOBILE_PLAN.md](roadmap/MONITOR_MOBILE_PLAN.md) | Мобильный мониторинг — этапы и контроль | План / очередь |
| [roadmap/PAYMENT_OPTIONS.md](roadmap/PAYMENT_OPTIONS.md) | Варианты поддержки разработчика и оплаты ARM | План / очередь |
| [roadmap/PC_BACKUP_PLAN.md](roadmap/PC_BACKUP_PLAN.md) | Резервные копии на компьютере владельца | План / очередь |
| [roadmap/PERSONAL_DATA_ENCRYPTION_PLAN.md](roadmap/PERSONAL_DATA_ENCRYPTION_PLAN.md) | Следующий этап: шифрование персональных данных | План / очередь |
| [roadmap/PHONE_DIRECTORY_PLAN.md](roadmap/PHONE_DIRECTORY_PLAN.md) | Общий справочник телефонов | План / очередь |
| [roadmap/PROFILE_RECOVERY_PLAN.md](roadmap/PROFILE_RECOVERY_PLAN.md) | Профиль и восстановление доступа | План / очередь |
| [roadmap/SECURITY_ROADMAP.md](roadmap/SECURITY_ROADMAP.md) | План устранения рисков безопасности ARM | План / очередь |
| [roadmap/TECHNICAL_DEBT.md](roadmap/TECHNICAL_DEBT.md) | Technical Debt & Refactoring Roadmap | План / очередь |
| [roadmap/WORK_TIME_RULES_PLAN.md](roadmap/WORK_TIME_RULES_PLAN.md) | Личный учёт смен и прогноз зарплаты: источники и план | План / очередь |
| [roadmap/armd-progress.md](roadmap/armd-progress.md) | Направление клона АРМ-ЛБ | План / очередь |
| [roadmap/timemanagment.md](roadmap/timemanagment.md) | Исходные требования к учёту рабочего времени | План / очередь |

## История выпусков

Подготовка ближайшего обновления: [безопасная публикация нарядов и отключение удаления](releases/2026-11-11-naryad-publication-safety.md) — проверено локально, ещё не выложено.

| Документ | Назначение | Тип |
| --- | --- | --- |
| [releases/2026-10-23-discipline.md](releases/2026-10-23-discipline.md) | Discipline module production deployment | Исторический отчёт |
| [releases/2026-10-24-mobile-discipline.md](releases/2026-10-24-mobile-discipline.md) | Mobile discipline API — 24 October 2026 | Исторический отчёт |
| [releases/2026-10-25-mobile-journal.md](releases/2026-10-25-mobile-journal.md) | Instructor journal — production release 25 October 2026 | Исторический отчёт |
| [releases/ACCOUNTS_PROFILE_2026-10-26.md](releases/ACCOUNTS_PROFILE_2026-10-26.md) | Логины и личные профили — 26 октября 2026 | Исторический отчёт |
| [releases/ATZ_RELEASE_2026-10-31.md](releases/ATZ_RELEASE_2026-10-31.md) | АТЗ: веб, API и Android — опубликовано | Исторический отчёт |
| [releases/DEPLOY_SUMMARY.md](releases/DEPLOY_SUMMARY.md) | docs/releases/DEPLOY_SUMMARY.md | Исторический отчёт |
| [releases/FULL_BACKUP_2026-10-30.md](releases/FULL_BACKUP_2026-10-30.md) | Первый зашифрованный recovery-набор ARM — 30 октября 2026 | Исторический отчёт |
| [releases/MOBILE_FIXES_2026-10-27.md](releases/MOBILE_FIXES_2026-10-27.md) | Ошибки ручной приёмки после 1.0.12 — 27–28 октября | Исторический отчёт |
| [releases/PERSONNEL_IMPORT_2026-10-26.md](releases/PERSONNEL_IMPORT_2026-10-26.md) | Картотека — 26 октября 2026 | Исторический отчёт |
| [releases/RELEASE_2026-10-27.md](releases/RELEASE_2026-10-27.md) | Общий выпуск веба и Android — 27 октября 2026 | Исторический отчёт |
| [releases/RELEASE_RECOVERY_2026-10-24.md](releases/RELEASE_RECOVERY_2026-10-24.md) | Выкладка профиля и восстановления пароля — 2026-10-24 | Исторический отчёт |
| [releases/chat-pilot-2026-11-06.md](releases/chat-pilot-2026-11-06.md) | Пилот чата на production — 06.11.2026 | Исторический отчёт |
| [releases/mobile-1.1.4.md](releases/mobile-1.1.4.md) | Мобильное 1.1.4 — 10.11.2026 | Исторический отчёт |
| [releases/mobile-login-1.1.3.md](releases/mobile-login-1.1.3.md) | Мобильный вход 1.1.3 — 10.11.2026 | Исторический отчёт |
| [releases/mobile-profile-icons-1.0.9.md](releases/mobile-profile-icons-1.0.9.md) | Мобильные иконки и профиль Telegram — 1.0.9 | Исторический отчёт |
| [releases/monitor-mobile-2026-11-06.md](releases/monitor-mobile-2026-11-06.md) | Закрытый Android-мониторинг — 06.11.2026 | Исторический отчёт |
| [releases/personnel-reconciliation-2026-11-06.md](releases/personnel-reconciliation-2026-11-06.md) | Сверка картотеки и доступов — 6 ноября 2026 | Исторический отчёт |
| [releases/podstroiki-mobile-1.1.2.md](releases/podstroiki-mobile-1.1.2.md) | Мобильные подстройки — production 09.11.2026 | Исторический отчёт |
| [releases/shared-phones-1.0.10.md](releases/shared-phones-1.0.10.md) | Общий справочник телефонов — APK 1.0.10 | Исторический отчёт |

## Материалы для руководства

Локальный ИИ: [комплект видео и инструкция](management/video/local-ai/README.md), [текст озвучки](management/video/local-ai/NARRATION.md), [ресурсы и этапы внедрения](management/video/local-ai/IMPLEMENTATION.md).

Новая видеоредакция: [ролик с авторской озвучкой и замечания к тексту](management/video/NARRATED_EDITION.md), [авторский текст](management/video/NARRATION_CUES.md), [тайминги](management/video/TIMING_NARRATED.csv).

| Документ | Назначение | Тип |
| --- | --- | --- |
| [management/ARM_DESIGN_DOCUMENT.md](management/ARM_DESIGN_DOCUMENT.md) | ARM — единая среда организации работы, контроля и обучения локомотивных бригад | Презентационные материалы |
| [management/DESIGN_DOCUMENT_OUTLINE.md](management/DESIGN_DOCUMENT_OUTLINE.md) | ARM: структура документа для руководства | Презентационные материалы |
| [management/SOURCE_INVENTORY.md](management/SOURCE_INVENTORY.md) | Реестр источников дизайн-документа ARM | Презентационные материалы |
| [management/presentation/README.md](management/presentation/README.md) | Презентация ARM для руководства | Презентационные материалы |
| [management/presentation/SPEAKER_NOTES.md](management/presentation/SPEAKER_NOTES.md) | Заметки выступающего | Презентационные материалы |
| [management/presentation/technical/SPEAKER_NOTES.md](management/presentation/technical/SPEAKER_NOTES.md) | Заметки выступающего | Презентационные материалы |
| [management/video/DESKTOP_ARM_NRD.md](management/video/DESKTOP_ARM_NRD.md) | Локальная часть АРМ: ARM-NRD | Презентационные материалы |
| [management/video/FUNCTION_COVERAGE.md](management/video/FUNCTION_COVERAGE.md) | Карта покрытия функций — рабочая редакция 09.11.2026 | Презентационные материалы |
| [management/video/NARRATION_CUES.md](management/video/NARRATION_CUES.md) | Тайминги обзорного видеоряда | Презентационные материалы |
| [management/video/PRODUCTION_PLAN.md](management/video/PRODUCTION_PLAN.md) | План видеопрезентации ARM и последующего обслуживания сервера | Презентационные материалы |
| [management/video/README.md](management/video/README.md) | Видеопрезентация ARM | Презентационные материалы |
| [management/video/REQUIREMENTS_AND_COSTS.md](management/video/REQUIREMENTS_AND_COSTS.md) | Развёртывание, нагрузка и архив: предварительная инженерная оценка | Презентационные материалы |
| [management/video/STORYBOARD.md](management/video/STORYBOARD.md) | Раскадровка — рабочая редакция 09.11.2026 | Презентационные материалы |

## Служебные инструкции

[Правила проекта](../AGENTS.md), [правила мобильной разработки](../mobile/AGENTS.md) и [точка входа Claude](../mobile/CLAUDE.md) остаются рядом с кодом, чтобы инструменты продолжали находить их автоматически.

[Карта переноса документов](DOCUMENT_MOVES.json) содержит старые и новые пути. Текстовые ссылки внутри репозитория обновлены; внешние закладки и ссылки на старые пути в прошлых коммитах автоматически не перенаправляются.
