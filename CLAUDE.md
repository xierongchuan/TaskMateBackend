# TaskMateServer — CLAUDE.md

Backend REST API для TaskMate (Laravel 12 + PHP 8.4). Общие правила см. в [../CLAUDE.md](../CLAUDE.md).

## Технологический стек

| Пакет | Версия | Назначение |
|-------|--------|------------|
| PHP | ^8.4 | Runtime |
| Laravel | ^12.0 | Framework |
| Laravel Sanctum | ^4.2 | API аутентификация |
| Predis | ^3.1 | Redis/Valkey клиент |
| laravel-queue-rabbitmq | ^14.4 | RabbitMQ driver |
| Pest PHP | ^4.0 | Тестирование |
| Laravel Pint | ^1.24 | Форматирование кода |

## Структура проекта

```
app/
├── Console/
│   ├── Commands/              # Artisan команды
│   │   ├── ArchiveCompletedTasks.php    # tasks:archive-completed
│   │   ├── ArchiveOverdueAfterShift.php # tasks:archive-overdue-after-shift
│   │   ├── CleanupTempProofUploads.php  # cleanup:temp-proofs
│   │   ├── CreateUserCommand.php        # Создание пользователя CLI
│   │   └── SeedDemoData.php             # db:seed-demo
│   └── Kernel.php             # Scheduler (расписание)
│
├── Enums/                     # 8 Enums
│   ├── Role.php               # owner, manager, observer, employee
│   ├── TaskStatus.php         # pending, acknowledged, pending_review, completed...
│   ├── TaskType.php           # individual, group
│   ├── ShiftStatus.php        # open, closed
│   ├── Priority.php           # low, medium, high
│   ├── Recurrence.php         # daily, weekly, monthly
│   ├── DateRange.php          # today, this_week, this_month, custom
│   └── ExpenseStatus.php
│
├── Exceptions/
│   ├── DuplicateTaskException.php
│   └── AccessDeniedException.php
│
├── Helpers/
│   └── TimeHelper.php         # UTC работа, nowUtc(), toIsoZulu()
│
├── Http/
│   ├── Controllers/Api/V1/    # 18 контроллеров (~5700 строк)
│   │   ├── SessionController.php        # login, logout, current
│   │   ├── UserApiController.php        # CRUD пользователей
│   │   ├── DealershipController.php     # CRUD автосалонов
│   │   ├── TaskController.php           # CRUD задач (22KB)
│   │   ├── TaskGeneratorController.php  # CRUD генераторов (27KB)
│   │   ├── TaskVerificationController.php # approve, reject (7KB)
│   │   ├── TaskProofController.php      # файлы доказательств (12KB)
│   │   ├── ShiftController.php          # смены (16KB)
│   │   ├── ShiftPhotoController.php     # фото смен (7KB)
│   │   ├── DashboardController.php      # статистика
│   │   ├── ArchivedTaskController.php   # архив (8KB)
│   │   ├── SettingsController.php       # настройки (14KB)
│   │   ├── ReportController.php         # отчёты (25KB)
│   │   ├── CalendarController.php       # выходные/праздники (14KB)
│   │   ├── AuditLogController.php       # журнал аудита (9KB)
│   │   ├── ImportantLinkController.php  # ссылки (7KB)
│   │   ├── NotificationSettingController.php # уведомления (10KB)
│   │   └── FileConfigController.php     # конфиг загрузки (2KB)
│   │
│   ├── Middleware/
│   │   └── CheckRole.php      # Проверка роли (иерархия 1-4)
│   │
│   ├── Requests/Api/V1/       # Form Requests (валидация)
│   │   ├── StoreTaskRequest.php
│   │   ├── UpdateTaskRequest.php
│   │   ├── StoreUserRequest.php
│   │   ├── UpdateUserRequest.php
│   │   ├── GetAuditLogsRequest.php
│   │   └── GetAuditActorsRequest.php
│   │
│   └── Resources/             # API Resources
│       ├── UserResource.php
│       └── ShiftResource.php
│
├── Jobs/                      # Фоновые задачи (RabbitMQ)
│   ├── ProcessTaskGeneratorsJob.php   # Генерация задач
│   ├── StoreTaskProofsJob.php         # Сохранение файлов
│   ├── StoreTaskSharedProofsJob.php   # Общие файлы
│   └── DeleteProofFileJob.php         # Удаление файлов
│
├── Models/                    # 19 Eloquent моделей
│   ├── User.php               # HasApiTokens, SoftDeletes, Auditable
│   ├── AutoDealership.php     # timezone, Auditable
│   ├── Task.php               # SoftDeletes, Auditable, toApiArray()
│   ├── TaskResponse.php       # Ответы на задачи
│   ├── TaskProof.php          # Файлы доказательств
│   ├── TaskSharedProof.php    # Общие файлы (group tasks)
│   ├── TaskVerificationHistory.php # История верификации
│   ├── TaskGenerator.php      # Шаблоны генерации, Auditable
│   ├── TaskAssignment.php     # Назначения, SoftDeletes
│   ├── Shift.php              # Смены
│   ├── ShiftReplacement.php   # Замены на смене
│   ├── Setting.php            # Системные настройки
│   ├── CalendarDay.php        # Выходные/рабочие дни
│   ├── AuditLog.php           # Журнал изменений
│   ├── ImportantLink.php      # Быстрые ссылки
│   └── ...
│
├── Services/                  # 11 сервисов
│   ├── TaskService.php        # CRUD задач, проверка дубликатов
│   ├── TaskFilterService.php  # Фильтрация и пагинация
│   ├── TaskProofService.php   # Загрузка файлов
│   ├── TaskVerificationService.php # Одобрение/отклонение
│   ├── DashboardService.php   # Статистика дашборда
│   ├── ShiftService.php       # Управление сменами
│   ├── SettingsService.php    # Системные настройки
│   └── FileValidation/        # Валидация файлов
│       ├── FileValidator.php
│       ├── FileValidationConfig.php
│       ├── FileTypeCategory.php
│       └── MimeTypeResolver.php  # Magic bytes проверка
│
├── Traits/                    # 3 Trait
│   ├── Auditable.php          # Автоматическое логирование
│   ├── HasDealershipAccess.php # Фильтрация по dealership
│   └── ApiResponses.php       # Стандартные ответы
│
└── Policies/                  # Авторизация (если есть)

config/
├── filesystems.php            # task_proofs disk (приватный)
├── queue.php                  # RabbitMQ connection
└── ...

database/
├── migrations/                # 48 миграций
└── seeders/
    └── DemoSeeder.php         # Демо-данные

routes/
└── api.php                    # 252 строки, 50+ endpoints

tests/
├── Feature/                   # 30+ Feature тестов
│   ├── TaskControllerTest.php
│   ├── TaskGeneratorControllerTest.php
│   ├── TaskVerificationControllerTest.php
│   ├── ShiftTest.php
│   └── ...
└── Unit/                      # Unit тесты
    ├── Jobs/
    ├── Models/
    └── Services/
```

## Модели — детально

### User

```php
// Роли (enum Role)
'owner'    => level 4, полный доступ
'manager'  => level 3, управление задачами и пользователями
'observer' => level 2, только просмотр
'employee' => level 1, выполнение задач

// Traits
HasApiTokens, HasFactory, SoftDeletes, Auditable

// Ключевые поля
login, full_name, phone, role, dealership_id
password, failed_login_attempts, locked_until  // Brute-force защита

// Связи
dealership()      // Первичный автосалон (belongsTo)
dealerships()     // Множество автосалонов (belongsToMany)
shifts(), taskAssignments(), taskResponses(), createdTasks()

// Методы
getAccessibleDealershipIds()  // Все доступные автосалоны
```

### Task

```php
// Типы задач
'individual' — один исполнитель
'group'      — несколько исполнителей

// Типы ответов
'notification'         — просто уведомление
'completion'           — требует подтверждения
'completion_with_proof' — требует файлы-доказательства

// Статусы (вычисляемый атрибут)
'pending', 'acknowledged', 'pending_review', 'completed', 'completed_late', 'overdue'

// Traits
HasFactory, SoftDeletes, Auditable

// Ключевые поля
title, description, comment
appear_date, deadline, scheduled_date  // Все в UTC
task_type, response_type, priority
tags (json), notification_settings (json)
is_active, archived_at, archive_reason
generator_id  // Если создана генератором

// Связи
creator(), dealership(), assignments(), responses(), sharedProofs(), generator()

// Методы
toApiArray()           // UTC даты с Z суффиксом
archive(?string $reason)
restoreFromArchive()
getStatusAttribute()   // Вычисляет статус из responses
```

### TaskResponse

```php
// Статусы
'pending', 'acknowledged', 'pending_review', 'completed', 'rejected'

// Ключевые поля
task_id, user_id, shift_id
status, comment, responded_at
verified_at, verified_by, rejection_reason, rejection_count
submission_source ('individual' | 'shared')
uses_shared_proofs (boolean)

// Связи
task(), user(), shift(), verifier(), proofs(), verificationHistory()

// Методы
getEffectiveProofsAttribute()  // Возвращает proofs или shared_proofs
canResubmit()
```

### TaskGenerator

```php
// Рекурентность
'daily'   — каждый день
'weekly'  — по дням недели (recurrence_days_of_week: [1,2,5] = Пн,Вт,Пт)
'monthly' — по дням месяца (recurrence_days_of_month: [1,15,-1] = 1-е, 15-е, последний)

// Ключевые поля
title, description, comment
creator_id, dealership_id
recurrence, recurrence_time, deadline_time  // HH:mm:ss в UTC
start_date, end_date, last_generated_at
task_type, response_type, priority, tags, notification_settings
is_active

// Методы
shouldGenerateToday(?Carbon $now)  // Проверяет нужно ли создать сегодня
getAppearTimeForDate(Carbon $date) // Время появления в UTC
getDeadlineTimeForDate(Carbon $date)
toApiArray()  // С статистикой (total_generated, completed_count)
```

### CalendarDay

```php
// Типы
'holiday'  — выходной
'workday'  — рабочий день

// Логика fallback
- Если dealership имеет свой календарь за год → ТОЛЬКО его
- Если нет → глобальный (dealership_id = null)

// Методы
isHoliday(Carbon $date, ?int $dealershipId)
hasOwnCalendarForYear(int $year, int $dealershipId)
copyGlobalToDealer(int $year, int $dealershipId)
resetToGlobal(int $year, int $dealershipId)
getYearCalendar(int $year, ?int $dealershipId)
```

## Сервисы

### TaskService

```php
createTask(array $data, User $creator)   // С проверкой дубликатов
updateTask(Task $task, array $data)
isDuplicate(array $data)                  // По title, task_type, dealership_id, is_active
syncAssignments(Task $task, array $userIds)

// Использует DB::transaction + HasDealershipAccess trait
```

### TaskProofService

```php
storeProof(TaskResponse $response, UploadedFile $file, int $dealershipId)
storeProofs(TaskResponse $response, array $files, int $dealershipId)
deleteProof(TaskProof $proof)
deleteAllProofs(TaskResponse $response)

// Хранилище: storage/app/private/task_proofs
// Лимиты: 5 файлов, 200MB total
// Использует FileValidator для magic bytes проверки
```

### TaskVerificationService

```php
approve(TaskResponse $response, User $verifier)
reject(TaskResponse $response, User $verifier, string $reason)
rejectAllForTask(Task $task, User $verifier, string $reason)

// При rejection: удаляет индивидуальные файлы (НЕ shared_proofs)
// Записывает в TaskVerificationHistory
```

### TaskFilterService

```php
getFilteredTasks(Request $request, User $currentUser)

// Фильтры:
// - date_range: today, this_week, this_month, custom range
// - dealership_id
// - status, priority
// - search (title/description)
// - deadline: overdue, today, upcoming
// - generator_id
// - task_type

// Eager loading всех relations для N+1 prevention
// Пагинация (default 15)
```

### DashboardService

```php
getDashboardData(?int $dealershipId)

// Возвращает:
// - getTaskStatistics() — active, completed_today, overdue
// - getActiveShifts()
// - getUserCount()
// - getPendingReviewCount(), getPendingReviewTasks()
// - getOverdueTasksList()
// - getLateShiftsCount()
// - getRecentTasks()
// - getGeneratorStats() — active, total, generated_today
```

## API Routes

### Публичные (без auth)

```
POST   /session                    # Логин (throttle:login)
GET    /config/file-upload         # Конфиг загрузки
GET    /shifts/{id}/photo/{type}   # Фото смены (signed URL)
GET    /task-proofs/{id}/download  # Скачать доказательство (signed URL)
```

### Защищённые (auth:sanctum)

```
# Session
GET    /session/current            # Текущий пользователь
DELETE /session                    # Логаут

# Users
GET    /users                      # Список (фильтры, пагинация)
GET    /users/{id}                 # Детали
POST   /users                      # Создать (manager/owner)
PUT    /users/{id}                 # Обновить (manager/owner)
DELETE /users/{id}                 # Удалить (manager/owner)

# Dealerships
GET    /dealerships                # Список
POST   /dealerships                # Создать (owner)
PUT    /dealerships/{id}           # Обновить (owner)
DELETE /dealerships/{id}           # Удалить (owner)

# Tasks
GET    /tasks                      # Список с фильтрами
GET    /tasks/{id}                 # Детали с relations
GET    /tasks/my-history           # Моя история ответов
POST   /tasks                      # Создать (manager/owner)
PUT    /tasks/{id}                 # Обновить (manager/owner)
PATCH  /tasks/{id}/status          # Обновить статус (все)
DELETE /tasks/{id}                 # Удалить (manager/owner)

# Task Verification
POST   /task-responses/{id}/approve       # Одобрить (manager/owner)
POST   /task-responses/{id}/reject        # Отклонить (manager/owner)
POST   /tasks/{id}/reject-all-responses   # Отклонить все (manager/owner)

# Task Proofs
GET    /task-proofs/{id}           # Информация
DELETE /task-proofs/{id}           # Удалить
DELETE /task-shared-proofs/{id}    # Удалить общий

# Task Generators
GET    /task-generators            # Список
GET    /task-generators/{id}       # Детали
GET    /task-generators/{id}/tasks # Созданные задачи
GET    /task-generators/{id}/stats # Статистика
POST   /task-generators            # Создать (manager/owner)
PUT    /task-generators/{id}       # Обновить (manager/owner)
DELETE /task-generators/{id}       # Удалить (manager/owner)
POST   /task-generators/{id}/pause # Приостановить
POST   /task-generators/{id}/resume# Возобновить
POST   /task-generators/pause-all  # Приостановить все
POST   /task-generators/resume-all # Возобновить все

# Archived Tasks
GET    /archived-tasks             # Список
GET    /archived-tasks/statistics  # Статистика
GET    /archived-tasks/export      # Export CSV
POST   /archived-tasks/{id}/restore# Восстановить (manager/owner)

# Shifts
GET    /shifts                     # Список
GET    /shifts/{id}                # Детали
GET    /shifts/current             # Текущие открытые
GET    /shifts/my                  # Мои смены
GET    /shifts/my/current          # Моя текущая
GET    /shifts/statistics          # Статистика
POST   /shifts                     # Открыть смену
PUT    /shifts/{id}                # Обновить
DELETE /shifts/{id}                # Удалить

# Settings
GET    /settings                   # Все настройки
GET    /settings/shift-config      # Конфиг смен
PUT    /settings/shift-config      # Обновить (owner)
GET    /settings/notification-config
PUT    /settings/notification-config
GET    /settings/archive-config
PUT    /settings/archive-config
GET    /settings/task-config
PUT    /settings/task-config

# Calendar
GET    /calendar                   # Календарь на год
GET    /calendar/holidays          # Только выходные
GET    /calendar/check             # Проверить дату
POST   /calendar                   # Добавить день (manager/owner)
PUT    /calendar/{id}              # Обновить
DELETE /calendar/{id}              # Удалить
POST   /calendar/bulk-update       # Массовое обновление
POST   /calendar/reset-to-global   # Сбросить к глобальному

# Dashboard
GET    /dashboard                  # Вся статистика

# Reports
GET    /reports                    # Отчёты
GET    /reports/issue-details      # Детали проблем

# Audit Logs
GET    /audit-logs                 # Журнал (owner)
GET    /audit-logs/actors          # Список пользователей
GET    /audit-logs/{table}/{id}    # История записи (manager/owner)

# Links
GET    /links                      # Список
POST   /links                      # Создать (manager/owner)
PUT    /links/{id}                 # Обновить
DELETE /links/{id}                 # Удалить

# Notification Settings
GET    /notification-settings      # Список
PUT    /notification-settings/{id} # Обновить (manager/owner)
POST   /notification-settings/bulk-update
POST   /notification-settings/reset
```

## Jobs и Commands

### Jobs (RabbitMQ)

```php
// ProcessTaskGeneratorsJob — каждые 5 мин через scheduler
// Обрабатывает все активные TaskGenerators
foreach (TaskGenerator::active()->get() as $generator) {
    if ($generator->shouldGenerateToday()) {
        // Создаёт Task с appear_date и deadline в UTC
        // Копирует assignments
        // Обновляет last_generated_at
    }
}

// StoreTaskProofsJob — асинхронное сохранение файлов
// StoreTaskSharedProofsJob — общие файлы
// DeleteProofFileJob — удаление из storage
```

### Commands (Scheduler)

```php
// tasks:archive-completed (каждые 10 мин)
// Архивирует завершённые по archive_completed_time
// Архивирует просроченные по archive_overdue_day_of_week + archive_overdue_time

// tasks:archive-overdue-after-shift (каждый час)
// Архивирует просроченные после закрытия смены

// cleanup:temp-proofs (каждый час)
// Очистка временных загруженных файлов
```

## Traits

### Auditable

```php
// Автоматическое логирование в AuditLog
// Слушает события: created, updated, deleted

// Записывает:
// - actor_id (текущий auth пользователь)
// - dealership_id (из модели или связанных)
// - table_name, record_id, action
// - payload (changed attributes)
```

### HasDealershipAccess

```php
// Методы для фильтрации по доступным автосалонам
// Используется в Service слое
```

## Хранилище файлов

```php
// config/filesystems.php
'disks' => [
    'task_proofs' => [
        'driver' => 'local',
        'root' => storage_path('app/private/task_proofs'),
        'visibility' => 'private',
    ],
]

// Доступ только через подписанные URL (60 мин)
// Проверка авторизации при генерации URL, не при скачивании
```

## Тестирование

```bash
# Все тесты
composer test
# или
php artisan test

# С покрытием (min 50%)
composer test:coverage

# Конкретный тест
php artisan test --filter=TaskControllerTest

# Воркеры
php artisan workers:test
php artisan workers:test overdue
```

## Code Quality

```bash
# Laravel Pint (форматирование)
vendor/bin/pint

# Проверка без изменений
vendor/bin/pint --test
```

## Ключевые архитектурные решения

1. **Все даты в UTC** — хранение и передача в ISO 8601 с Z суффиксом
2. **Eager loading везде** — `with()` в контроллерах для N+1 prevention
3. **Приватное хранилище** — файлы недоступны напрямую, только signed URLs
4. **Magic bytes валидация** — проверка содержимого файлов, не только расширения
5. **Асинхронные операции** — файлы сохраняются через Jobs
6. **Автоматический аудит** — Auditable trait логирует все изменения
7. **Multi-tenant** — все данные фильтруются по dealership_id
8. **Soft deletes** — User, Task, TaskAssignment не удаляются физически

## Ключевые файлы

- `routes/api.php` — все API роуты
- `app/Console/Kernel.php` — расписание scheduler
- `config/filesystems.php` — настройки хранилища
- `config/queue.php` — RabbitMQ конфигурация
- `database/seeders/DemoSeeder.php` — демо-данные
- `supervisor.conf` — конфигурация фоновых процессов
