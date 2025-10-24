# User Registration API

## Overview

This document describes the user registration system. There are two endpoints available for creating users:

1. **Public endpoint** - `/api/v1/users/create` - For creating users without authentication
2. **Authenticated endpoint** - `/api/v1/users` - For creating users with authentication (requires auth token)

Both endpoints provide the same functionality but serve different use cases.

## Endpoints

### POST /api/v1/users/create

Create a new user/employee without requiring authentication.

**Headers:**
```
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
    "login": "testuser",
    "password": "TestPass123",
    "full_name": "Test User",
    "phone": "+79991234567",
    "role": "employee",
    "telegram_id": 123456789,
      "dealership_id": 1
}
```

**Required Fields:**
- `login` (string, min: 4, max: 255, unique)
- `password` (string, min: 8, max: 255, must contain uppercase, lowercase, and digit)
- `full_name` (string, min: 2, max: 255)
- `phone` (string, regex: phone number format)
- `role` (string, enum: owner, manager, observer, employee)

**Optional Fields:**
- `telegram_id` (integer, unique)
- `dealership_id` (integer, exists in auto_dealerships table)

**Success Response (201):**
```json
{
    "success": true,
    "message": "Сотрудник успешно создан",
    "data": {
        "id": 1,
        "login": "testuser",
        "full_name": "Test User",
        "phone": "+79991234567",
        "role": "employee",
        "telegram_id": 123456789,
              "dealership_id": 1,
        "created_at": "2024-01-01T12:00:00.000000Z",
        "updated_at": "2024-01-01T12:00:00.000000Z"
    }
}
```

**Error Response (422):**
```json
{
    "success": false,
    "message": "Ошибка валидации",
    "errors": {
        "login": ["Логин обязателен"],
        "password": ["Пароль должен содержать минимум 8 символов"],
        // ... other validation errors
    }
}
```

### POST /api/v1/users

Create a new user/employee with authentication required.

**Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer <your-token>
```

Request body and responses are identical to the public endpoint.

## Validation Rules

### Password Requirements
- Minimum 8 characters
- Must contain at least one uppercase letter
- Must contain at least one lowercase letter
- Must contain at least one digit
- Can contain special characters: @$!%*?&

### Phone Format
- Must match regex: `^\+?[\d\s\-\(\)]+$`
- Examples: `+79991234567`, `8 (999) 123-45-67`

### Role Values
Valid roles (enum values):
- `owner` - Владелец
- `manager` - Управляющий
- `observer` - Смотрящий
- `employee` - Сотрудник

## Usage Examples

### Using curl (Public Endpoint)
```bash
curl -X POST http://localhost/api/v1/users/create \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "login": "newuser",
    "password": "Password123",
    "full_name": "New User",
    "phone": "+79991234567",
    "role": "employee"
  }'
```

### Using curl (Authenticated Endpoint)
```bash
curl -X POST http://localhost/api/v1/users \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer your-api-token" \
  -d '{
    "login": "newuser",
    "password": "Password123",
    "full_name": "New User",
    "phone": "+79991234567",
    "role": "employee"
  }'
```

## Security Considerations

- The public endpoint is rate-limited to 50 requests per 1440 minutes (24 hours)
- The authenticated endpoint is rate-limited to 220 requests per minute
- Passwords are automatically hashed using Laravel's Hash facade
- All input is validated to prevent injection attacks
- Phone numbers are normalized for consistency
