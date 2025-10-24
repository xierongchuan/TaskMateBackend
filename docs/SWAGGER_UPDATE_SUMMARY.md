# Swagger API Documentation Update

## Overview

The swagger.yaml file has been completely updated to reflect the current state of the TaskMate Telegram Bot API after VCRM removal and implementation of the new user registration system.

## Key Updates Made

### 1. API Description
- Updated title and description to reflect VCRM-free architecture
- Changed from: "API для управления задачами, сменами и автосалонами в телеграм-боте TaskMate"
- Changed to: "API для управления задачами, сменами, автосалонами и пользователями в телеграм-боте TaskMate. Полностью автономная система без внешних зависимостей."

### 2. Updated Tags
- Enhanced Users tag description to include registration functionality
- Added new Webhook tag for Telegram bot webhook endpoint
- Updated tag descriptions to be more comprehensive

### 3. New Schemas Added

#### UserRegistrationResponse Schema
```yaml
UserRegistrationResponse:
  type: object
  properties:
    success:
      type: boolean
      example: true
    message:
      type: string
      example: Сотрудник успешно создан
    data:
      $ref: '#/components/schemas/User'
```

#### ValidationError Schema
```yaml
ValidationError:
  type: object
  properties:
    success:
      type: boolean
      example: false
    message:
      type: string
      example: Ошибка валидации
    errors:
      type: object
      additionalProperties:
        type: array
        items:
          type: string
      example:
        login: ["Логин обязателен"]
        password: ["Пароль должен содержать минимум 8 символов"]
```

#### TelegramWebhook Schema
```yaml
TelegramWebhook:
  type: object
  properties:
    update_id:
      type: integer
      example: 123456789
    message:
      type: object
      # ... detailed message structure
```

### 4. New Endpoints Added

#### POST /api/v1/users (Authenticated User Creation)
- **Tag**: Users
- **Summary**: Создать нового сотрудника (с аутентификацией)
- **Security**: Bearer token required
- **Request Body**: Complete user creation schema with validation
- **Response**: UserRegistrationResponse schema
- **Features**: Full validation, all user fields supported

#### POST /api/v1/users/create (Public User Creation)
- **Tag**: Users
- **Summary**: Создать нового сотрудника (публичный эндпоинт)
- **Security**: No authentication required
- **Request Body**: Same as authenticated endpoint
- **Response**: UserRegistrationResponse schema
- **Features**: Rate limited to 50 requests per 24 hours

#### POST /api/webhook (Telegram Webhook)
- **Tag**: Webhook
- **Summary**: Telegram Bot Webhook
- **Request Body**: TelegramWebhook schema
- **Response**: Simple success/error response
- **Features**: Handles all Telegram bot updates

### 5. Enhanced User Management

#### GET /api/v1/users (Updated)
- Still provides user listing with pagination
- Phone filtering with normalization support
- Authentication required

#### GET /api/v1/users/{id} (Unchanged)
- Get user by ID functionality maintained
- Authentication required

#### GET /api/v1/users/{id}/status (Unchanged)
- User status checking functionality
- Authentication required

### 6. Validation Rules

#### Password Requirements (Updated)
- **Minimum length**: 8 characters (reduced from 12 for user registration)
- **Required patterns**: At least one lowercase, one uppercase, one digit
- **Optional**: Special characters allowed
- **Example**: Password123

#### Phone Validation
- **Pattern**: `^\+?[\d\s\-\(\)]+$`
- **Supports**: International phone formats
- **Length**: Maximum 20 characters

#### Role Validation
- **Enum values**: employee, manager, observer, owner
- **Case sensitive**: Exact string matching

### 7. Security Features

#### Authentication
- **Bearer Token**: Laravel Sanctum tokens
- **Required**: For all authenticated endpoints
- **Rate Limiting**:
  - Authenticated endpoints: 220 requests per minute
  - Public endpoints: 50 requests per 1440 minutes

#### Error Responses
- **422 Unprocessable Entity**: Validation errors with detailed field messages
- **401 Unauthorized**: Missing or invalid authentication
- **404 Not Found**: Resource not found
- **500 Internal Server Error**: Server error handling

### 8. Response Format Standardization

#### Success Responses
```json
{
  "success": true,
  "message": "Операция выполнена успешно",
  "data": { ... }
}
```

#### Error Responses
```json
{
  "success": false,
  "message": "Описание ошибки",
  "errors": {
    "field_name": ["Сообщение об ошибке"]
  }
}
```

## API Usage Examples

### Create User (Authenticated)
```bash
curl -X POST http://localhost/api/v1/users \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-api-token" \
  -d '{
    "login": "newuser123",
    "password": "Password123",
    "full_name": "Иван Иванов",
    "phone": "+79001234567",
    "role": "employee",
    "telegram_id": 123456789,
    "company_id": 1,
    "dealership_id": 1
  }'
```

### Create User (Public)
```bash
curl -X POST http://localhost/api/v1/users/create \
  -H "Content-Type: application/json" \
  -d '{
    "login": "newuser123",
    "password": "Password123",
    "full_name": "Иван Иванов",
    "phone": "+79001234567",
    "role": "employee"
  }'
```

### Webhook Configuration
```bash
# Set webhook for Telegram bot
curl -X POST https://api.telegram.org/bot<token>/setWebhook \
  -d '{
    "url": "https://yourdomain.com/api/webhook"
  }'
```

## Benefits of Updated Documentation

1. **Complete Coverage**: All API endpoints documented
2. **VCRM-Free**: No references to external systems
3. **Enhanced User Management**: Comprehensive user creation workflows
4. **Clear Validation**: Detailed error messages and field validation
5. **Security Clarity**: Authentication requirements clearly specified
6. **Developer Friendly**: Rich examples and response schemas
7. **Future Proof**: Extensible schema design for new features

## Validation Status

✅ **YAML Syntax**: Valid and parseable
✅ **Schema Completeness**: All endpoints have proper schemas
✅ **Security Documentation**: Authentication and authorization clearly defined
✅ **Error Handling**: Comprehensive error response documentation
✅ **Example Coverage**: Request/response examples for all major use cases

The swagger.yaml file is now fully up-to-date and provides comprehensive API documentation that reflects the current VCRM-free architecture and new user registration system.