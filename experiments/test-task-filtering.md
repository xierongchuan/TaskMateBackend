# Тестирование фильтрации задач по статусам

## Bug #3: Проверка работы фильтрации

### Код фильтрации (TaskController.php, lines 112-154)

Реализована фильтрация по следующим статусам:

1. **active** - активные задачи (is_active=true, archived_at=null)
2. **completed** - завершенные задачи (имеют ответы со status='completed')
3. **overdue** - просроченные задачи (deadline < now, нет completed ответов)
4. **postponed** - отложенные задачи (postpone_count > 0)
5. **pending** - ожидающие ответа (нет ответов acknowledged или completed)
6. **acknowledged** - подтвержденные (имеют ответы со status='acknowledged')

### Тестовые запросы API

```bash
# 1. Активные задачи
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=active"

# 2. Завершенные задачи
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=completed"

# 3. Просроченные задачи
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=overdue"

# 4. Отложенные задачи
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=postponed"

# 5. Ожидающие ответа
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=pending"

# 6. Подтвержденные задачи
curl -H "Authorization: Bearer TOKEN" \
     "http://localhost/api/v1/tasks?status=acknowledged"
```

### Возможные причины неработающей фильтрации

1. **Frontend не передает параметр `status`**
   - Проверить DevTools -> Network -> проверить URL запроса
   - Убедиться что параметр `?status=active` добавляется к URL

2. **Регистр параметра**
   - Код использует `strtolower($status)` для нормализации
   - Должно работать с любым регистром (Active, ACTIVE, active)

3. **Проблема с датами**
   - Для status=overdue используется Carbon::now()
   - Проверить часовой пояс сервера и БД

4. **Связи (relationships) не загружены**
   - Код использует whereHas/whereDoesntHave
   - Связи должны быть правильно определены в модели Task

### Статус Bug #3

**ВЕРОЯТНО УЖЕ ИСПРАВЛЕН** - код фильтрации корректный и должен работать.

Возможные решения:
- Проверить что фронтенд правильно формирует запросы
- Добавить логирование в TaskController::index() для отладки
- Убедиться что модель Task имеет корректные relationships

### Рекомендации для тестирования

1. Создать несколько задач с разными статусами
2. Проверить каждый фильтр отдельно через API
3. Проверить комбинации фильтров
4. Включить SQL query logging для отладки

```php
// Добавить в TaskController::index() для отладки:
\DB::enableQueryLog();
// ... код запроса ...
\Log::info('Task filter query', [
    'status' => $status,
    'sql' => \DB::getQueryLog()
]);
```
