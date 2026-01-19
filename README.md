# TaskMateBackend

Backend приложение для системы TaskMate, реализующее REST API (Laravel).

## Стек технологий

- **Framework**: Laravel 12
- **Language**: PHP 8.4
- **Database**: PostgreSQL 18
- **Cache/Queue**: Valkey (Redis compatible)

- **REST API**: Полнофункциональное API для веб-интерфейса.
- **Telegram Bot**: Обработка команд сотрудников, отправка уведомлений, фотофиксация смен.
- **Task Generators**: Автоматизация регулярных процессов (ежедневно, еженедельно, ежемесячно).
- **Notification Center**: Гибкая рассылка уведомлений через различные каналы.
- **Maintenance Mode**: Возможность перевода системы в режим обслуживания.
- **Soft Deletion**: Система мягкого удаления для задач и пользователей.
- **Pest Testing**: Полноценное покрытие тестами API и бизнес-логики.

## Установка и запуск

### Требования

- PHP 8.4+
- Composer
- Docker & Docker Compose

### Установка зависимостей

```sh
composer install
```

### Запуск в Docker

```sh
docker compose up -d --build
```

### Инициализация после первого запуска

```sh
# Установка зависимостей (если vendor/ отсутствует)
docker compose exec backend_api composer install

# Миграции и сидинг демо-данных
docker compose exec backend_api php artisan migrate --force
docker compose exec backend_api php artisan db:seed-demo

# Создание симлинка для публичных файлов
docker compose exec backend_api php artisan storage:link
```

### Тестирование

```sh
# Все тесты (193 теста)
docker compose exec backend_api php artisan test

# Отдельные наборы тестов
docker compose exec backend_api composer test:unit      # Unit tests
docker compose exec backend_api composer test:feature   # Feature tests
docker compose exec backend_api composer test:api       # API endpoint tests
docker compose exec backend_api composer test:coverage  # С отчётом покрытия (min 50%)
```

## Seeding (Заполнение данными)

Чтобы создать пользователя администратора и демо-данные:

```sh
docker compose exec src_telegram_bot_api php artisan db:seed
```

Это создаст:

- **1 Admin user** (owner)
- **3 Автосалона** со своими менеджерами и сотрудниками
- **Задачи и назначения** для каждого салона
- **Генераторы задач** для демонстрации автоматизации
- **Важные ссылки**

**Данные для входа:**

- Admin: `admin` / `password`

**Демо Салоны:**

- **Avto Salon Center**: Manager `manager1`, Employees `emp1_1`, `emp1_2`
- **Avto Salon Sever**: Manager `manager2`, Employees `emp2_1`, `emp2_2`
- **Auto Salon Lux**: Manager `manager3`, Employees `emp3_1`, `emp3_2`

**Пароли всех пользователей:** `password`

## Лицензия

Proprietary License
Copyright © 2023-2026 [https://github.com/xierongchuan](https://github.com/xierongchuan). All rights reserved.
