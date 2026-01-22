# TaskMateBackend — CLAUDE.md

Backend REST API для TaskMate. Общие правила см. в [../CLAUDE.md](../CLAUDE.md).

## Структура проекта

```
app/
├── Console/           # Artisan команды
├── Http/
│   ├── Controllers/   # REST API контроллеры
│   ├── Requests/      # Form Requests (валидация)
│   └── Resources/     # API Resources (форматирование)
├── Jobs/              # Фоновые задачи
├── Models/            # Eloquent модели
├── Services/          # Бизнес-логика
└── Exceptions/        # Кастомные исключения

tests/
├── Unit/              # Unit тесты
├── Feature/           # Feature тесты
└── Api/               # API тесты
```

## Основные модели

| Модель | Описание | Особенности |
|--------|----------|-------------|
| User | Пользователи | Роли: employee, manager, observer, owner. SoftDeletes |
| AutoDealership | Автосалоны | Multi-tenant. SoftDeletes |
| Task | Задачи | Типы: notification, completion, completion_with_proof. SoftDeletes |
| Shift | Смены | Статусы: open, closed. Фото открытия/закрытия |
| Setting | Настройки | Типы: string, integer, boolean, json, time |

## Сервисы

- `TaskService` — CRUD задач, проверка дубликатов
- `TaskFilterService` — фильтрация задач по параметрам
- `TaskProofService` — загрузка и валидация доказательств
- `DashboardService` — аналитика для дашборда
- `ShiftService` — управление сменами
- `SettingsService` — системные настройки

## Workflow задач с доказательствами

```
pending → in_progress → pending_review → verified
                    ↘   (rejected) ↩ in_progress
```

Сотрудник загружает файлы → статус `pending_review` → менеджер проверяет → `verified` или `rejected` (с комментарием).

## Тестирование

```bash
# Внутри контейнера или через docker compose exec backend_api
composer test              # Все тесты
composer test:unit         # Unit
composer test:feature      # Feature
composer test:api          # API
composer test:coverage     # С покрытием (min 50%)

# Тестирование воркеров
php artisan workers:test           # Все
php artisan workers:test overdue   # Конкретный
```

## Code Quality

```bash
vendor/bin/pint              # Laravel Pint
vendor/bin/php-cs-fixer fix  # PHP CS Fixer
```

## Ключевые файлы

- `routes/api.php` — API роуты
- `config/filesystems.php` — настройки хранилища (готово к S3)
- `app/Console/Kernel.php` — расписание воркеров
- `database/seeders/DemoSeeder.php` — демо-данные

## Дополнительно

- [README.md](README.md) — полное описание проекта
- [swagger.yaml](swagger.yaml) — OpenAPI спецификация
