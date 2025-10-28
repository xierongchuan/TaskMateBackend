# Test: Recurring Tasks Implementation

This document contains test scenarios for the recurring tasks feature.

## Test Environment Setup

1. Ensure migrations are run:
```bash
php artisan migrate
```

2. Create test data (users, dealerships, etc.)

## Test Scenarios

### 1. Daily Recurring Task

**Create a daily task that appears every day at 09:00**

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Ежедневная утренняя проверка",
    "description": "Проверить наличие товара на складе",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "acknowledge",
    "recurrence": "daily",
    "recurrence_time": "09:00",
    "assignments": [1, 2]
  }'
```

**Expected behavior:**
- Task created successfully
- Every day at 09:00 (Asia/Yekaterinburg), notifications sent to assigned users
- `last_recurrence_at` updated after each processing
- Task not processed on weekends (if weekend_days setting is configured)

**Test the command:**
```bash
php artisan tasks:process-recurring
```

### 2. Weekly Recurring Task

**Create a weekly task for Monday at 10:00**

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Еженедельная встреча команды",
    "description": "Планирование задач на неделю",
    "dealership_id": 1,
    "task_type": "group",
    "response_type": "acknowledge",
    "recurrence": "weekly",
    "recurrence_day_of_week": 1,
    "recurrence_time": "10:00",
    "assignments": [1, 2, 3]
  }'
```

**Expected behavior:**
- Task created successfully
- Every Monday at 10:00, notifications sent to assigned users
- Task skipped if Monday is a configured weekend day
- `last_recurrence_at` updated after processing

**Day of week values:**
- 1 = Monday
- 2 = Tuesday
- 3 = Wednesday
- 4 = Thursday
- 5 = Friday
- 6 = Saturday
- 7 = Sunday

### 3. Monthly Recurring Task - Specific Day

**Create a monthly task for the 15th at 14:00**

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Ежемесячный отчет продаж",
    "description": "Подготовить отчет за прошлый месяц",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "complete",
    "recurrence": "monthly",
    "recurrence_day_of_month": 15,
    "recurrence_time": "14:00",
    "assignments": [1]
  }'
```

**Expected behavior:**
- Task created successfully
- Every 15th of the month at 14:00, notification sent
- `last_recurrence_at` updated after processing

### 4. Monthly Recurring Task - First Day of Month

**Create a monthly task for the first day of month**

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Начало месяца - инвентаризация",
    "description": "Проверить остатки на начало месяца",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "complete",
    "recurrence": "monthly",
    "recurrence_day_of_month": -1,
    "recurrence_time": "08:00",
    "assignments": [1, 2]
  }'
```

**Expected behavior:**
- Task created successfully
- On the 1st of every month at 08:00, notifications sent
- Special value `-1` is interpreted as "first day of month"

### 5. Monthly Recurring Task - Last Day of Month

**Create a monthly task for the last day of month**

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Конец месяца - закрытие периода",
    "description": "Подготовить данные для бухгалтерии",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "complete",
    "recurrence": "monthly",
    "recurrence_day_of_month": -2,
    "recurrence_time": "18:00",
    "assignments": [1]
  }'
```

**Expected behavior:**
- Task created successfully
- On the last day of every month at 18:00, notification sent
- Special value `-2` is interpreted as "last day of month"
- Handles months with different lengths (28, 29, 30, 31 days)

## Validation Tests

### Test 1: Daily task without time

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Invalid daily task",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "acknowledge",
    "recurrence": "daily",
    "assignments": [1]
  }'
```

**Expected response:** 422 error with message "Для ежедневных задач необходимо указать время (recurrence_time)"

### Test 2: Weekly task without day of week

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Invalid weekly task",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "acknowledge",
    "recurrence": "weekly",
    "recurrence_time": "10:00",
    "assignments": [1]
  }'
```

**Expected response:** 422 error with message "Для еженедельных задач необходимо указать день недели (recurrence_day_of_week)"

### Test 3: Monthly task without day of month

```bash
curl -X POST http://localhost:8000/api/v1/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "title": "Invalid monthly task",
    "dealership_id": 1,
    "task_type": "individual",
    "response_type": "acknowledge",
    "recurrence": "monthly",
    "recurrence_time": "10:00",
    "assignments": [1]
  }'
```

**Expected response:** 422 error with message "Для ежемесячных задач необходимо указать число месяца (recurrence_day_of_month)"

## Weekend Configuration

To configure weekend days for a dealership:

```bash
curl -X POST http://localhost:8000/api/v1/settings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "dealership_id": 1,
    "key": "weekend_days",
    "value": "[6, 7]",
    "type": "json",
    "description": "Выходные дни (6=Суббота, 7=Воскресенье)"
  }'
```

**Custom weekends example (Sunday and Monday):**
```json
{
  "dealership_id": 1,
  "key": "weekend_days",
  "value": "[7, 1]",
  "type": "json",
  "description": "Выходные дни (7=Воскресенье, 1=Понедельник)"
}
```

## Checking Task Status

**Get all recurring tasks:**
```bash
curl -X GET http://localhost:8000/api/v1/tasks?recurrence=daily \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Get task details:**
```bash
curl -X GET http://localhost:8000/api/v1/tasks/{task_id} \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Manual Testing with Artisan Command

**Run the recurring tasks processor manually:**
```bash
php artisan tasks:process-recurring
```

**Check logs:**
```bash
tail -f storage/logs/laravel.log | grep "recurring"
```

## Testing Time-Sensitive Scenarios

To test time-sensitive scenarios, you may need to:

1. Modify system time (use with caution in dev environment)
2. Adjust `recurrence_time` values to be close to current time
3. Monitor logs to see processing results

## Troubleshooting

**Task not processing:**
- Check `is_active` is true
- Check `recurrence` field is set to daily/weekly/monthly
- Check required fields (recurrence_time, recurrence_day_of_week, etc.) are set
- Check current time vs target time
- Check if today is a weekend for the dealership
- Check `last_recurrence_at` to see if already processed today

**Notifications not sent:**
- Check users are assigned to the task
- Check users have telegram_id configured
- Check TaskNotificationService is working
- Check queue workers are running

## Expected Database State

After successful processing, the `tasks` table should show:

```sql
SELECT id, title, recurrence, recurrence_time, recurrence_day_of_week,
       recurrence_day_of_month, last_recurrence_at
FROM tasks
WHERE recurrence IS NOT NULL;
```

The `last_recurrence_at` field should be updated to the UTC timestamp when the task was last processed.
