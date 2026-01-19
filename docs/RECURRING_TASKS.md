# Recurring Tasks Documentation

## Overview

The recurring tasks feature allows creation of tasks that automatically notify users on a regular schedule (daily, weekly, or monthly). This is useful for routine activities that need to be completed regularly.

## Features

- **Daily tasks**: Execute every day at a specific time
- **Weekly tasks**: Execute on a specific day of the week at a specific time
- **Monthly tasks**: Execute on a specific day of the month at a specific time
- **Weekend exclusion**: Tasks are not processed on configured weekend days
- **Timezone support**: All times are handled in Asia/Yekaterinburg timezone
- **Duplicate prevention**: Tasks are not processed more than once per period

## Database Schema

The `tasks` table includes the following fields for recurring tasks:

| Field | Type | Description |
|-------|------|-------------|
| `recurrence` | enum | Type of recurrence: `daily`, `weekly`, `monthly`, or `null` |
| `recurrence_time` | time | Time of day to process task (HH:MM format) |
| `recurrence_day_of_week` | integer | Day of week for weekly tasks (1=Monday, 7=Sunday) |
| `recurrence_day_of_month` | integer | Day of month for monthly tasks (-1=first, -2=last, 1-31=specific day) |
| `last_recurrence_at` | timestampTz | UTC timestamp of last processing |

## API Usage

### Creating a Daily Task

```json
POST /api/v1/tasks
{
  "title": "Daily morning check",
  "description": "Check inventory levels",
  "dealership_id": 1,
  "task_type": "individual",
  "response_type": "acknowledge",
  "recurrence": "daily",
  "recurrence_time": "09:00",
  "assignments": [1, 2]
}
```

**Requirements for daily tasks:**
- `recurrence` must be "daily"
- `recurrence_time` must be provided in HH:MM format
- Time is in Asia/Yekaterinburg timezone

### Creating a Weekly Task

```json
POST /api/v1/tasks
{
  "title": "Weekly team meeting",
  "description": "Plan tasks for the week",
  "dealership_id": 1,
  "task_type": "group",
  "response_type": "acknowledge",
  "recurrence": "weekly",
  "recurrence_day_of_week": 1,
  "recurrence_time": "10:00",
  "assignments": [1, 2, 3]
}
```

**Requirements for weekly tasks:**
- `recurrence` must be "weekly"
- `recurrence_day_of_week` must be 1-7 (1=Monday, 7=Sunday)
- `recurrence_time` is optional but recommended

**Day of week values:**
- 1 = Monday
- 2 = Tuesday
- 3 = Wednesday
- 4 = Thursday
- 5 = Friday
- 6 = Saturday
- 7 = Sunday

### Creating a Monthly Task (Specific Day)

```json
POST /api/v1/tasks
{
  "title": "Monthly sales report",
  "description": "Prepare report for previous month",
  "dealership_id": 1,
  "task_type": "individual",
  "response_type": "complete",
  "recurrence": "monthly",
  "recurrence_day_of_month": 15,
  "recurrence_time": "14:00",
  "assignments": [1]
}
```

### Creating a Monthly Task (First Day)

```json
POST /api/v1/tasks
{
  "title": "Start of month inventory",
  "description": "Check stock levels",
  "dealership_id": 1,
  "task_type": "individual",
  "response_type": "complete",
  "recurrence": "monthly",
  "recurrence_day_of_month": -1,
  "recurrence_time": "08:00",
  "assignments": [1, 2]
}
```

### Creating a Monthly Task (Last Day)

```json
POST /api/v1/tasks
{
  "title": "End of month closing",
  "description": "Prepare accounting data",
  "dealership_id": 1,
  "task_type": "individual",
  "response_type": "complete",
  "recurrence": "monthly",
  "recurrence_day_of_month": -2,
  "recurrence_time": "18:00",
  "assignments": [1]
}
```

**Requirements for monthly tasks:**
- `recurrence` must be "monthly"
- `recurrence_day_of_month` must be:
  - `-1` for first day of month
  - `-2` for last day of month
  - `1-31` for specific day
- `recurrence_time` is optional but recommended

## Processing Logic

### Command

Recurring tasks are processed by the `ProcessRecurringTasks` command:

```bash
php artisan tasks:process-recurring
```

This command should be scheduled to run frequently (e.g., every 5-15 minutes) via cron or Laravel scheduler.

### Processing Rules

#### Daily Tasks

1. Check if current time >= `recurrence_time`
2. Check if task was not already processed today
3. Check if today is not a weekend
4. Send notifications to all assigned users
5. Update `last_recurrence_at` to current timestamp

#### Weekly Tasks

1. Check if today's day of week matches `recurrence_day_of_week`
2. If `recurrence_time` is set, check if current time >= that time
3. Check if task was not already processed this week
4. Check if today is not a weekend
5. Send notifications to all assigned users
6. Update `last_recurrence_at` to current timestamp

#### Monthly Tasks

1. Determine target day:
   - If `recurrence_day_of_month` is -1: first day (1st)
   - If `recurrence_day_of_month` is -2: last day of month
   - Otherwise: use value as-is
2. Check if today matches target day
3. If `recurrence_time` is set, check if current time >= that time
4. Check if task was not already processed this month
5. Check if today is not a weekend
6. Send notifications to all assigned users
7. Update `last_recurrence_at` to current timestamp

### Weekend Exclusion

The system checks if the current day is a weekend for the dealership before processing recurring tasks. Weekend configuration is stored in the `settings` table:

```json
{
  "dealership_id": 1,
  "key": "weekend_days",
  "value": "[6, 7]",
  "type": "json"
}
```

Default weekends are Saturday (6) and Sunday (7). This can be customized per dealership.

## Timezone Handling

All recurring task processing uses the Asia/Yekaterinburg timezone (UTC+5). Times are:

1. **Input**: User provides times in Asia/Yekaterinburg
2. **Storage**: `last_recurrence_at` stored in UTC
3. **Processing**: Converted to Asia/Yekaterinburg for comparison
4. **Output**: Returned to users in Asia/Yekaterinburg

## Validation

The API validates recurring task creation/update:

- Daily tasks MUST have `recurrence_time`
- Weekly tasks MUST have `recurrence_day_of_week` (1-7)
- Monthly tasks MUST have `recurrence_day_of_month` (-2 to 31)

Invalid requests return 422 status with appropriate error message.

## Example Scheduler Configuration

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Process recurring tasks every 10 minutes
    $schedule->command('tasks:process-recurring')
        ->everyTenMinutes()
        ->withoutOverlapping();
}
```

## Monitoring

### Check Logs

```bash
tail -f storage/logs/laravel.log | grep "recurring"
```

### Query Recurring Tasks

```sql
SELECT id, title, recurrence, recurrence_time,
       recurrence_day_of_week, recurrence_day_of_month,
       last_recurrence_at
FROM tasks
WHERE recurrence IS NOT NULL
  AND is_active = true;
```

### Check Last Processing

```sql
SELECT id, title, recurrence,
       last_recurrence_at,
       last_recurrence_at AT TIME ZONE 'Asia/Yekaterinburg' as last_processed_local
FROM tasks
WHERE recurrence IS NOT NULL
ORDER BY last_recurrence_at DESC;
```

## Troubleshooting

### Task Not Processing

**Check 1: Is the task active?**
```sql
SELECT is_active FROM tasks WHERE id = ?;
```

**Check 2: Are required fields set?**
```sql
SELECT recurrence, recurrence_time, recurrence_day_of_week, recurrence_day_of_month
FROM tasks WHERE id = ?;
```

**Check 3: Was it already processed?**
```sql
SELECT last_recurrence_at AT TIME ZONE 'Asia/Yekaterinburg' as last_processed
FROM tasks WHERE id = ?;
```

**Check 4: Is today a weekend?**
```sql
SELECT value FROM settings
WHERE dealership_id = ? AND key = 'weekend_days';
```

**Check 5: Is the time right?**
- For daily tasks: current time must be >= `recurrence_time`
- For weekly tasks: must be the right day of week
- For monthly tasks: must be the right day of month

### Notifications Not Sent

**Check 1: Are users assigned?**
```sql
SELECT user_id FROM task_assignments WHERE task_id = ?;
```

**Check 2: Do users have telegram_id?**
```sql
SELECT u.id, u.name, u.telegram_id
FROM users u
JOIN task_assignments ta ON ta.user_id = u.id
WHERE ta.task_id = ?;
```

**Check 3: Is the notification service working?**
Check logs for errors in `TaskNotificationService`.

## Best Practices

1. **Set realistic times**: Allow buffer time for processing (don't set to exact minute)
2. **Monitor logs**: Regularly check for errors in recurring task processing
3. **Test thoroughly**: Use test scripts in `experiments/` directory
4. **Configure weekends**: Set appropriate weekend days for each dealership
5. **Use descriptive titles**: Make it clear that tasks are recurring
6. **Assign appropriate users**: Ensure users are available on recurring schedule

## Related Files

- Command: `app/Console/Commands/ProcessRecurringTasks.php`
- Model: `app/Models/Task.php`
- Controller: `app/Http/Controllers/Api/V1/TaskController.php`
- Migration: `database/migrations/2025_10_28_000002_add_recurring_task_fields.php`
- Tests: `experiments/test-recurring-tasks.md`
- Logic test: `experiments/test-recurring-logic.php`

## See Also

- [README_WORKERS.md](../README_WORKERS.md) - Information about all background workers
- [CLAUDE.md](../CLAUDE.md) - Project overview and architecture
- [API_USER_REGISTRATION.md](API_USER_REGISTRATION.md) - API documentation
