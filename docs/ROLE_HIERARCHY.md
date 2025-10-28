# Иерархия ролей и контроль доступа

## Роли в системе

TaskMateTelegramBot имеет четыре уровня ролей с иерархией доступа:

### 1. Owner (Владелец)
**Уровень доступа**: Максимальный

**Возможности**:
- Полный доступ ко всем функциям системы
- Управление всеми автосалонами
- Создание и удаление пользователей всех ролей
- Изменение глобальных настроек
- Управление настройками бота
- Просмотр всей статистики и отчетов

### 2. Manager (Управляющий)
**Уровень доступа**: Высокий

**Возможности**:
- Управление задачами (создание, редактирование, удаление)
- Управление сменами
- Просмотр и управление сотрудниками своего автосалона
- Просмотр статистики своего автосалона
- Получение ежедневных и еженедельных отчетов
- Изменение настроек своего автосалона

**Ограничения**:
- Не может управлять другими автосалонами
- Не может изменять глобальные настройки
- Не может назначать роли owner или manager

### 3. Observer (Наблюдатель)
**Уровень доступа**: Средний (только чтение)

**Возможности**:
- Просмотр всех задач своего автосалона
- Просмотр смен сотрудников
- Просмотр статистики
- Получение отчетов

**Ограничения**:
- Не может создавать или изменять задачи
- Не может управлять сменами
- Не может изменять данные пользователей
- Не может изменять настройки

### 4. Employee (Сотрудник)
**Уровень доступа**: Базовый

**Возможности**:
- Просмотр своих задач
- Ответы на задачи (OK, Выполнено)
- Отложить задачу
- Открытие и закрытие своих смен
- Просмотр своей статистики

**Ограничения**:
- Не может видеть задачи других сотрудников
- Не может создавать задачи
- Не может просматривать статистику других
- Не может изменять настройки

## Использование middleware

### В routes/api.php

```php
// Доступ только для владельцев
Route::post('/dealerships', [DealershipController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:owner']);

// Доступ для менеджеров и владельцев
Route::post('/tasks', [TaskController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:manager,owner']);

// Доступ для всех, кроме сотрудников
Route::get('/users', [UserApiController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:observer,manager,owner']);

// Доступ для всех авторизованных
Route::get('/tasks', [TaskController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:employee,observer,manager,owner']);
```

### В контроллерах

```php
use App\Http\Middleware\CheckRole;

// Проверка роли в методе контроллера
public function updateSensitiveData(Request $request)
{
    if (!CheckRole::hasRoleOrHigher($request->user()->role, 'manager')) {
        return response()->json([
            'message' => 'Требуется роль менеджера или выше'
        ], 403);
    }

    // ... логика метода
}
```

## Контроль доступа к endpoints

### Аутентификация (Session/Auth)
- `POST /api/v1/session` - Нет ограничений (публичный логин)
- `DELETE /api/v1/session` - Авторизованные пользователи
- `GET /api/v1/session/current` - Авторизованные пользователи

**⚠️ ВАЖНО**: Endpoint `/api/v1/register` удален. Регистрация новых пользователей возможна только через API `/api/v1/users` менеджерами и владельцами.

### Пользователи (Users)
- `GET /api/v1/users` - Все авторизованные пользователи
- `GET /api/v1/users/{id}` - Все авторизованные пользователи
- `GET /api/v1/users/{id}/status` - Все авторизованные пользователи
- `POST /api/v1/users` - **Только Manager, Owner** (создание новых пользователей)
- `PUT /api/v1/users/{id}` - **Только Manager, Owner** (редактирование пользователей)
- `DELETE /api/v1/users/{id}` - **Только Manager, Owner** (удаление пользователей)

### Автосалоны (Dealerships)
- `GET /api/v1/dealerships` - Все авторизованные пользователи
- `GET /api/v1/dealerships/{id}` - Все авторизованные пользователи
- `POST /api/v1/dealerships` - **Только Manager, Owner**
- `PUT /api/v1/dealerships/{id}` - **Только Manager, Owner**
- `DELETE /api/v1/dealerships/{id}` - **Только Manager, Owner**

### Задачи (Tasks)
- `GET /api/v1/tasks` - Все авторизованные пользователи
- `GET /api/v1/tasks/postponed` - Все авторизованные пользователи
- `GET /api/v1/tasks/{id}` - Все авторизованные пользователи
- `POST /api/v1/tasks` - **Только Manager, Owner**
- `PUT /api/v1/tasks/{id}` - **Только Manager, Owner**
- `DELETE /api/v1/tasks/{id}` - **Только Manager, Owner**

### Смены (Shifts)
- `GET /api/v1/shifts` - Все авторизованные пользователи
- `GET /api/v1/shifts/current` - Все авторизованные пользователи
- `GET /api/v1/shifts/statistics` - Все авторизованные пользователи
- `GET /api/v1/shifts/{id}` - Все авторизованные пользователи

### Настройки (Settings)
- `GET /api/v1/settings` - Все авторизованные пользователи
- `GET /api/v1/settings/shift-config` - Все авторизованные пользователи
- `GET /api/v1/settings/bot-config` - Все авторизованные пользователи
- `GET /api/v1/settings/{key}` - Все авторизованные пользователи
- `POST /api/v1/settings/shift-config` - **Только Manager, Owner**
- `POST /api/v1/settings/bot-config` - **Только Manager, Owner**
- `POST /api/v1/settings` - **Только Manager, Owner**
- `PUT /api/v1/settings/{id}` - **Только Manager, Owner**
- `DELETE /api/v1/settings/{id}` - **Только Manager, Owner**

### Dashboard
- `GET /api/v1/dashboard` - Все авторизованные пользователи

## Проверка в коде

### Быстрая проверка роли

```php
use App\Enums\Role;

// Точное совпадение роли
if ($user->role === Role::OWNER->value) {
    // Пользователь - владелец
}

// Проверка уровня доступа
if (CheckRole::hasRoleOrHigher($user->role, 'manager')) {
    // Пользователь - менеджер или владелец
}
```

### В Blade шаблонах

```blade
@if(auth()->user()->role === 'owner')
    <button>Удалить автосалон</button>
@endif

@if(in_array(auth()->user()->role, ['manager', 'owner']))
    <button>Создать задачу</button>
@endif
```

## Миграция существующих endpoints

При добавлении middleware к существующим endpoints:

1. **Постепенное внедрение**: Начните с критичных endpoints (удаление, изменение настроек)
2. **Тестирование**: Проверьте доступ для каждой роли
3. **Документация**: Обновите swagger.yaml с информацией о требуемых ролях
4. **Обратная совместимость**: Убедитесь что фронтенд обрабатывает 403 ошибки

## Безопасность

### Лучшие практики

1. **Principle of Least Privilege**: Давайте минимально необходимые права
2. **Defense in Depth**: Проверяйте права на уровне middleware И в контроллерах
3. **Audit Logging**: Логируйте все действия с повышенными привилегиями
4. **Regular Review**: Периодически проверяйте назначенные роли

### Примеры потенциальных уязвимостей

```php
// ❌ ПЛОХО - не проверяет права
public function deleteUser($id)
{
    User::find($id)->delete();
}

// ✅ ХОРОШО - проверяет права
public function deleteUser(Request $request, $id)
{
    if (!CheckRole::hasRoleOrHigher($request->user()->role, 'owner')) {
        abort(403);
    }

    User::find($id)->delete();
}
```

## Тестирование

### Примеры тестов для ролей

```php
// tests/Feature/RoleAccessTest.php
test('employee cannot create tasks', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->post('/api/v1/tasks', [...])
        ->assertStatus(403);
});

test('manager can create tasks', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)
        ->post('/api/v1/tasks', [...])
        ->assertStatus(201);
});
```

## Дополнительная информация

- **Enum Role**: `app/Enums/Role.php`
- **Middleware**: `app/Http/Middleware/CheckRole.php`
- **Routes**: `routes/api.php`
- **User Model**: `app/Models/User.php`
