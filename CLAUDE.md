# TaskMateTelegramBot

## О проекте

TaskMateTelegramBot - это автономная система управления задачами и сменами для автосалонов с интеграцией Telegram. Бот помогает менеджерам эффективно управлять сотрудниками, задачами, сменами и получать уведомления в реальном времени.

## Технический стек

- **Backend**: Laravel 12 (PHP 8.4)
- **База данных**: PostgreSQL
- **Кеширование и очереди**: Valkey (Redis-совместимый)
- **Telegram API**: Nutgram (Laravel интеграция)
- **Аутентификация**: Laravel Sanctum
- **API документация**: OpenAPI/Swagger 3.0
- **Тестирование**: Pest PHP
- **Code Quality**: PHP CS Fixer, PHP_CodeSniffer, Laravel Pint

## Архитектура проекта

### Структура директорий

```
app/
├── Bot/                    # Telegram бот логика
│   ├── Commands/           # Команды бота (/start, /help и т.д.)
│   ├── Conversations/      # Диалоги с пользователями
│   ├── Handlers/           # Обработчики callback'ов
│   └── Middleware/         # Middleware для бота
├── Console/                # Artisan команды
├── Http/
│   ├── Controllers/        # REST API контроллеры
│   └── Middleware/         # HTTP middleware
├── Jobs/                   # Фоновые задачи и воркеры
├── Models/                 # Eloquent модели
├── Services/               # Бизнес-логика
└── Traits/                 # Переиспользуемые трейты

database/
├── migrations/             # Миграции БД
├── seeders/               # Сиды для тестовых данных
└── factories/             # Фабрики для тестирования

docs/                       # Документация проекта
tests/                      # Тесты (Pest PHP)
```

### Основные модели данных

#### User
Пользователи системы (сотрудники, менеджеры, владельцы)
- **Роли**: employee, manager, observer, owner
- **Связи**: автосалон, смены, задачи, ответы на задачи

#### AutoDealership
Автосалоны (филиалы компании)
- **Данные**: название, адрес, телефон, описание
- **Связи**: пользователи, смены, задачи, настройки

#### Task
Задачи для сотрудников
- **Типы**: individual (индивидуальная), group (групповая)
- **Ответы**: acknowledge (подтверждение), complete (выполнение)
- **Функции**: повторяющиеся задачи (daily, weekly, monthly), дедлайны, теги
- **Связи**: создатель, исполнители, ответы

#### Shift
Смены сотрудников
- **Статусы**: open, closed
- **Функции**: отслеживание опозданий, фото открытия/закрытия, замены
- **Связи**: пользователь, автосалон, замена

#### Setting
Настройки системы
- **Типы**: string, integer, boolean, json, time
- **Уровни**: глобальные или по автосалону
- **Примеры**: время смен, допустимое опоздание

## Ключевые функции

### 1. Управление задачами
- Создание индивидуальных и групповых задач
- Назначение задач пользователям или ролям
- Дедлайны с напоминаниями (за 1, 2, 4 часа)
- Повторяющиеся задачи (ежедневные, еженедельные, ежемесячные)
- Теги для категоризации
- Отслеживание статусов (pending, acknowledged, completed, overdue)
- Возможность отложить задачу
- Архивация старых задач

### 2. Система уведомлений
Автоматические уведомления через Telegram:
- Новые задачи при наступлении `appear_date`
- Напоминания о приближающихся дедлайнах
- Уведомления о просроченных задачах
- Напоминания о задачах без ответа
- Ежедневные сводки для менеджеров (20:00)
- Еженедельные отчеты (понедельник 09:00)

### 3. Управление сменами
- Открытие/закрытие смен
- Фотоотчеты при открытии и закрытии
- Отслеживание опозданий
- Система замен сотрудников
- Статистика по сменам
- Запланированное время смен

### 4. REST API
Полноценный REST API для интеграции с фронтендом или другими системами:
- Аутентификация (login/logout/register)
- CRUD операции для всех сущностей
- Расширенная фильтрация и поиск
- Пагинация
- Dashboard с аналитикой

### 5. Telegram Bot
Интуитивный интерфейс через Telegram:
- Регистрация пользователей
- Просмотр и управление задачами
- Управление сменами
- Интерактивные кнопки (OK, Выполнено, Перенести)
- Поддержка callback'ов для быстрых действий

## Фоновые воркеры (Jobs)

Система использует очереди (queue) для выполнения фоновых задач:

### Расписание воркеров

| Воркер | Частота | Назначение |
|--------|---------|------------|
| `SendScheduledTasksJob` | 5 минут | Отправка задач когда наступает appear_date |
| `CheckOverdueTasksJob` | 10 минут | Проверка и уведомление о просроченных задачах |
| `CheckUpcomingDeadlinesJob` | 15 минут | Напоминания о дедлайнах (за 1ч, 2ч, 4ч) |
| `CheckUnrespondedTasksJob` | 30 минут | Напоминания о задачах без ответа (2ч, 6ч, 24ч) |
| `SendDailySummaryJob` | 20:00 ежедневно | Ежедневная сводка для менеджеров |
| `SendWeeklyReportJob` | Пн 09:00 | Еженедельные отчеты |
| `ArchiveOldTasksJob` | 02:00 ежедневно | Архивация старых выполненных задач |

Подробнее см. [README_WORKERS.md](README_WORKERS.md)

## Контекст из TaskMate

TaskMateTelegramBot является частью экосистемы TaskMate, которая включает:
- **TaskMateAPI** - основной API сервер
- **TaskMateBackend** - административная панель
- **TaskMateFrontend** - пользовательский интерфейс
- **TaskMateTelegramBot** - Telegram интеграция (этот репозиторий)
- **VanillaFlowTelegramBot** - дополнительный бот для workflow

Все компоненты разворачиваются через Docker Compose и работают автономно.

## Установка и запуск

### Требования
- PHP 8.4+
- Composer
- Docker и Docker Compose (рекомендуется)
- PostgreSQL (или через Docker)
- Valkey/Redis (или через Docker)

### Установка зависимостей
```bash
composer install
```

### Конфигурация
1. Скопировать `.env.example` в `.env`
2. Настроить переменные окружения:
   - `TELEGRAM_TOKEN` - токен бота от @BotFather
   - `DB_*` - настройки базы данных
   - `REDIS_*` - настройки Valkey/Redis
3. Сгенерировать ключ приложения:
```bash
php artisan key:generate
```

### Запуск через Docker
```bash
docker-compose up -d --build
```

### Миграции и сиды
```bash
php artisan migrate
php artisan db:seed  # опционально для тестовых данных
```

### Запуск воркеров
```bash
# В продакшене используйте Supervisor (см. supervisor.conf)
php artisan queue:work --queue=notifications --sleep=3 --tries=3
```

### Настройка Telegram webhook
```bash
php artisan nutgram:hook:set
```

## Разработка

### Запуск в режиме разработки
```bash
composer dev
```
Это запустит:
- PHP сервер (artisan serve)
- Queue воркер
- Логи (pail)
- Vite dev сервер

### Тестирование
```bash
composer test
# или
php artisan test
```

### Проверка кода
```bash
# PHP CS Fixer
vendor/bin/php-cs-fixer fix

# PHP CodeSniffer
vendor/bin/phpcs

# Laravel Pint
vendor/bin/pint
```

### Тестирование воркеров
```bash
# Все воркеры
php artisan workers:test

# Конкретный воркер
php artisan workers:test overdue
php artisan workers:test upcoming
php artisan workers:test unresponded
```

## API документация

API документирован в формате OpenAPI 3.0 в файле [swagger.yaml](swagger.yaml).

Основные эндпоинты:
- `/api/v1/session` - Аутентификация
- `/api/v1/users` - Управление пользователями
- `/api/v1/dealerships` - Управление автосалонами
- `/api/v1/shifts` - Управление сменами
- `/api/v1/tasks` - Управление задачами
- `/api/v1/settings` - Настройки системы
- `/api/v1/dashboard` - Дашборд для менеджеров

Подробнее см. [docs/API_USER_REGISTRATION.md](docs/API_USER_REGISTRATION.md)

## Безопасность

- Аутентификация через Laravel Sanctum (Bearer tokens)
- Rate limiting для всех API эндпоинтов
- Валидация всех входных данных
- Хеширование паролей (bcrypt)
- CORS настройки
- Audit logging для всех действий пользователей
- Проприетарная лицензия - см. [LICENSE.md](LICENSE.md)

## Мониторинг

### Просмотр логов
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Worker logs (если используется Supervisor)
sudo supervisorctl tail taskmate-workers

# Realtime logs
php artisan pail
```

### Мониторинг очередей
```bash
# Статус очереди
php artisan queue:monitor notifications

# Очистка зависших задач
php artisan queue:clear notifications

# Повтор неудачных задач
php artisan queue:retry all
```

## Производительность

- Redis/Valkey для кеширования и очередей
- Оптимизированные SQL запросы с индексами
- Eager loading для предотвращения N+1 проблем
- Кеширование конфигурации и роутов в продакшене
- Rate limiting для защиты от перегрузки

## Поддержка часовых поясов

Система поддерживает работу с разными часовыми поясами:
- Все даты хранятся в UTC
- Конвертация в локальный часовой пояс пользователя при отображении
- Настройка часового пояса в профиле пользователя

## Лицензия и авторские права

```
License: Proprietary License
Copyright: © 2023-2025 谢榕川 All rights reserved
```

Использование, копирование, распространение и модификация этого программного обеспечения возможны только с явного письменного разрешения правообладателя.

## Контакты и поддержка

- GitHub Issues: https://github.com/xierongchuan/TaskMateTelegramBot/issues
- Upstream Repository: https://github.com/xierongchuan/TaskMate

## Дополнительные ресурсы

- [README.md](README.md) - Краткое описание и быстрый старт
- [README_WORKERS.md](README_WORKERS.md) - Подробная информация о воркерах
- [swagger.yaml](swagger.yaml) - OpenAPI спецификация
- [docs/](docs/) - Дополнительная документация
- [check-scheduler.md](check-scheduler.md) - Проверка планировщика задач

---

Issue to solve: undefined
Your prepared branch: issue-27-79c8a5a2
Your prepared working directory: /tmp/gh-issue-solver-1761753927223
Your forked repository: konard/TaskMateTelegramBot
Original repository (upstream): xierongchuan/TaskMateTelegramBot

Proceed.