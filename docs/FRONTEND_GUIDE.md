# Руководство по разработке фронтенда для TaskMate API

Это подробное руководство поможет вам создать фронтенд-приложение для работы с TaskMateTelegramBot API.

## 📋 Содержание

1. [Обзор API](#обзор-api)
2. [Технологический стек](#технологический-стек)
3. [Структура проекта](#структура-проекта)
4. [Аутентификация и авторизация](#аутентификация-и-авторизация)
5. [Работа с пользователями](#работа-с-пользователями)
6. [Управление задачами](#управление-задачами)
7. [Управление сменами](#управление-сменами)
8. [Автосалоны](#автосалоны)
9. [Настройки](#настройки)
10. [Dashboard](#dashboard)
11. [Обработка ошибок](#обработка-ошибок)
12. [Лучшие практики](#лучшие-практики)

---

## Обзор API

### Базовая информация

- **Base URL**: `https://your-domain.com/api/v1`
- **Формат**: JSON
- **Аутентификация**: Bearer Token (Laravel Sanctum)
- **Документация**: `swagger.yaml` в корне проекта

### Основные endpoints

```
POST   /session              - Логин
DELETE /session              - Логаут
GET    /session/current      - Получить текущего пользователя

GET    /users                - Список пользователей
POST   /users                - Создать пользователя (Manager/Owner)
GET    /users/{id}           - Информация о пользователе
PUT    /users/{id}           - Обновить пользователя (Manager/Owner)
DELETE /users/{id}           - Удалить пользователя (Manager/Owner)

GET    /tasks                - Список задач
POST   /tasks                - Создать задачу (Manager/Owner)
GET    /tasks/{id}           - Информация о задаче
PUT    /tasks/{id}           - Обновить задачу (Manager/Owner)
DELETE /tasks/{id}           - Удалить задачу (Manager/Owner)

GET    /shifts               - Список смен
GET    /shifts/current       - Текущие смены
GET    /shifts/statistics    - Статистика смен

GET    /dealerships          - Список автосалонов
POST   /dealerships          - Создать автосалон (Manager/Owner)
PUT    /dealerships/{id}     - Обновить автосалон (Manager/Owner)
DELETE /dealerships/{id}     - Удалить автосалон (Manager/Owner)

GET    /settings             - Настройки
POST   /settings             - Создать настройку (Manager/Owner)
GET    /settings/bot-config  - Настройки бота
PUT    /settings/bot-config  - Обновить настройки бота (Manager/Owner)

GET    /dashboard            - Общая статистика
```

---

## Технологический стек

### Рекомендуемые технологии

#### Вариант 1: React + TypeScript
```bash
npm create vite@latest taskmate-frontend -- --template react-ts
cd taskmate-frontend
npm install
```

**Установка зависимостей:**
```bash
npm install axios react-router-dom
npm install @tanstack/react-query  # для управления запросами
npm install zustand                 # для state management
npm install date-fns                # для работы с датами
npm install react-hook-form         # для форм
npm install @headlessui/react       # UI компоненты (опционально)
npm install tailwindcss            # CSS framework (опционально)
```

#### Вариант 2: Vue 3 + TypeScript
```bash
npm create vue@latest taskmate-frontend
cd taskmate-frontend
npm install
```

**Установка зависимостей:**
```bash
npm install axios vue-router
npm install @tanstack/vue-query    # для управления запросами
npm install pinia                   # для state management
npm install date-fns                # для работы с датами
npm install vee-validate           # для форм
```

#### Вариант 3: Next.js (React + SSR)
```bash
npx create-next-app@latest taskmate-frontend --typescript
cd taskmate-frontend
npm install axios
npm install @tanstack/react-query
npm install zustand
```

---

## Структура проекта

### Рекомендуемая структура для React

```
src/
├── api/                    # API клиент и endpoints
│   ├── client.ts           # Axios instance с interceptors
│   ├── auth.ts             # Auth endpoints
│   ├── users.ts            # Users endpoints
│   ├── tasks.ts            # Tasks endpoints
│   ├── shifts.ts           # Shifts endpoints
│   ├── dealerships.ts      # Dealerships endpoints
│   └── settings.ts         # Settings endpoints
│
├── components/             # Переиспользуемые компоненты
│   ├── ui/                 # Базовые UI компоненты
│   │   ├── Button.tsx
│   │   ├── Input.tsx
│   │   ├── Modal.tsx
│   │   └── Select.tsx
│   ├── auth/               # Компоненты аутентификации
│   │   ├── LoginForm.tsx
│   │   └── ProtectedRoute.tsx
│   ├── users/              # Компоненты пользователей
│   │   ├── UserList.tsx
│   │   ├── UserForm.tsx
│   │   └── UserCard.tsx
│   ├── tasks/              # Компоненты задач
│   │   ├── TaskList.tsx
│   │   ├── TaskForm.tsx
│   │   ├── TaskCard.tsx
│   │   └── TaskFilters.tsx
│   └── layout/             # Layout компоненты
│       ├── Header.tsx
│       ├── Sidebar.tsx
│       └── Layout.tsx
│
├── pages/                  # Страницы приложения
│   ├── LoginPage.tsx
│   ├── DashboardPage.tsx
│   ├── UsersPage.tsx
│   ├── TasksPage.tsx
│   ├── ShiftsPage.tsx
│   ├── SettingsPage.tsx
│   └── NotFoundPage.tsx
│
├── stores/                 # State management
│   ├── authStore.ts        # Хранилище аутентификации
│   ├── userStore.ts        # Хранилище пользователей
│   └── appStore.ts         # Общее хранилище
│
├── hooks/                  # Custom hooks
│   ├── useAuth.ts
│   ├── useUsers.ts
│   ├── useTasks.ts
│   └── usePermissions.ts
│
├── types/                  # TypeScript типы
│   ├── api.ts              # API типы
│   ├── user.ts             # User типы
│   ├── task.ts             # Task типы
│   └── common.ts           # Общие типы
│
├── utils/                  # Утилиты
│   ├── auth.ts             # Auth утилиты
│   ├── date.ts             # Работа с датами
│   ├── permissions.ts      # Проверка прав
│   └── validation.ts       # Валидация
│
├── constants/              # Константы
│   ├── api.ts              # API константы
│   ├── roles.ts            # Роли
│   └── routes.ts           # Роуты
│
├── App.tsx                 # Главный компонент
└── main.tsx                # Entry point
```

---

## Аутентификация и авторизация

### Шаг 1: Создание API клиента

**`src/api/client.ts`**

```typescript
import axios, { AxiosInstance, AxiosError } from 'axios';

const BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

// Создаем axios instance
const apiClient: AxiosInstance = axios.create({
  baseURL: BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 30000, // 30 секунд
});

// Request interceptor - добавляем токен к каждому запросу
apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor - обработка ошибок
apiClient.interceptors.response.use(
  (response) => {
    return response;
  },
  (error: AxiosError) => {
    // Обработка 401 - неавторизован
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }

    // Обработка 403 - недостаточно прав
    if (error.response?.status === 403) {
      console.error('Недостаточно прав:', error.response.data);
    }

    return Promise.reject(error);
  }
);

export default apiClient;
```

### Шаг 2: Типы данных

**`src/types/user.ts`**

```typescript
export type Role = 'employee' | 'observer' | 'manager' | 'owner';

export interface User {
  id: number;
  login: string;
  full_name: string;
  role: Role;
  dealership_id: number | null;
  telegram_id: number | null;
  phone: string | null;
  created_at: string;
  updated_at: string;
}

export interface LoginRequest {
  login: string;
  password: string;
}

export interface LoginResponse {
  message: string;
  token: string;
  user: User;
}
```

**`src/types/api.ts`**

```typescript
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  required_roles?: string[];
  your_role?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
```

### Шаг 3: Auth API endpoints

**`src/api/auth.ts`**

```typescript
import apiClient from './client';
import { LoginRequest, LoginResponse, User } from '../types/user';

export const authApi = {
  // Логин
  login: async (credentials: LoginRequest): Promise<LoginResponse> => {
    const response = await apiClient.post<LoginResponse>('/session', credentials);
    return response.data;
  },

  // Логаут
  logout: async (): Promise<void> => {
    await apiClient.delete('/session');
  },

  // Получить текущего пользователя
  getCurrentUser: async (): Promise<User> => {
    const response = await apiClient.get<User>('/session/current');
    return response.data;
  },
};
```

### Шаг 4: Auth Store (Zustand)

**`src/stores/authStore.ts`**

```typescript
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { User } from '../types/user';
import { authApi } from '../api/auth';

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;

  // Actions
  login: (login: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  clearError: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,
      error: null,

      login: async (login: string, password: string) => {
        set({ isLoading: true, error: null });
        try {
          const response = await authApi.login({ login, password });

          // Сохраняем токен
          localStorage.setItem('auth_token', response.token);

          set({
            user: response.user,
            token: response.token,
            isAuthenticated: true,
            isLoading: false,
          });
        } catch (error: any) {
          set({
            error: error.response?.data?.message || 'Ошибка входа',
            isLoading: false,
          });
          throw error;
        }
      },

      logout: async () => {
        try {
          await authApi.logout();
        } catch (error) {
          console.error('Logout error:', error);
        } finally {
          localStorage.removeItem('auth_token');
          set({
            user: null,
            token: null,
            isAuthenticated: false,
          });
        }
      },

      refreshUser: async () => {
        const token = localStorage.getItem('auth_token');
        if (!token) {
          set({ isAuthenticated: false, user: null });
          return;
        }

        try {
          const user = await authApi.getCurrentUser();
          set({ user, isAuthenticated: true });
        } catch (error) {
          console.error('Refresh user error:', error);
          localStorage.removeItem('auth_token');
          set({ isAuthenticated: false, user: null, token: null });
        }
      },

      clearError: () => set({ error: null }),
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({
        token: state.token,
        user: state.user,
      }),
    }
  )
);
```

### Шаг 5: Login компонент

**`src/components/auth/LoginForm.tsx`**

```typescript
import React, { useState } from 'react';
import { useAuthStore } from '../../stores/authStore';
import { useNavigate } from 'react-router-dom';

export const LoginForm: React.FC = () => {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const { login: loginAction, isLoading, error } = useAuthStore();
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      await loginAction(login, password);
      navigate('/dashboard');
    } catch (error) {
      // Ошибка уже обработана в store
    }
  };

  return (
    <div className="login-form">
      <h2>Вход в систему</h2>

      <form onSubmit={handleSubmit}>
        <div className="form-group">
          <label>Логин</label>
          <input
            type="text"
            value={login}
            onChange={(e) => setLogin(e.target.value)}
            required
            disabled={isLoading}
          />
        </div>

        <div className="form-group">
          <label>Пароль</label>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            disabled={isLoading}
          />
        </div>

        {error && (
          <div className="error-message">
            {error}
          </div>
        )}

        <button type="submit" disabled={isLoading}>
          {isLoading ? 'Вход...' : 'Войти'}
        </button>
      </form>
    </div>
  );
};
```

### Шаг 6: Protected Route

**`src/components/auth/ProtectedRoute.tsx`**

```typescript
import React, { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { Role } from '../../types/user';

interface ProtectedRouteProps {
  children: React.ReactNode;
  requiredRoles?: Role[];
}

export const ProtectedRoute: React.FC<ProtectedRouteProps> = ({
  children,
  requiredRoles,
}) => {
  const { isAuthenticated, user, refreshUser } = useAuthStore();

  useEffect(() => {
    // Проверяем актуальность данных пользователя
    if (isAuthenticated && !user) {
      refreshUser();
    }
  }, [isAuthenticated, user, refreshUser]);

  // Не авторизован - редирект на логин
  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  // Проверяем роли
  if (requiredRoles && user && !requiredRoles.includes(user.role)) {
    return (
      <div className="access-denied">
        <h2>Доступ запрещен</h2>
        <p>У вас недостаточно прав для просмотра этой страницы.</p>
        <p>Требуемые роли: {requiredRoles.join(', ')}</p>
        <p>Ваша роль: {user.role}</p>
      </div>
    );
  }

  return <>{children}</>;
};
```

### Шаг 7: Роутинг

**`src/App.tsx`**

```typescript
import React, { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './stores/authStore';

// Pages
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { UsersPage } from './pages/UsersPage';
import { TasksPage } from './pages/TasksPage';
import { SettingsPage } from './pages/SettingsPage';

// Components
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import { Layout } from './components/layout/Layout';

function App() {
  const { isAuthenticated, refreshUser } = useAuthStore();

  useEffect(() => {
    // При загрузке приложения обновляем данные пользователя
    if (isAuthenticated) {
      refreshUser();
    }
  }, []);

  return (
    <BrowserRouter>
      <Routes>
        {/* Публичные роуты */}
        <Route path="/login" element={<LoginPage />} />

        {/* Защищенные роуты */}
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <Layout />
            </ProtectedRoute>
          }
        >
          <Route index element={<Navigate to="/dashboard" replace />} />

          <Route path="dashboard" element={<DashboardPage />} />

          <Route path="tasks" element={<TasksPage />} />

          {/* Только для Manager и Owner */}
          <Route
            path="users"
            element={
              <ProtectedRoute requiredRoles={['manager', 'owner']}>
                <UsersPage />
              </ProtectedRoute>
            }
          />

          <Route path="settings" element={<SettingsPage />} />
        </Route>

        {/* 404 */}
        <Route path="*" element={<div>Страница не найдена</div>} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
```

---

## Работа с пользователями

### Шаг 1: Users API

**`src/api/users.ts`**

```typescript
import apiClient from './client';
import { User } from '../types/user';
import { PaginatedResponse } from '../types/api';

export interface CreateUserRequest {
  login: string;
  password: string;
  full_name: string;
  phone: string;
  role: 'employee' | 'observer' | 'manager' | 'owner';
  telegram_id?: number;
  dealership_id?: number;
}

export interface UpdateUserRequest {
  password?: string;
  full_name?: string;
  phone_number?: string;
  role?: string;
  dealership_id?: number;
}

export interface UsersFilters {
  search?: string;
  login?: string;
  name?: string;
  role?: string;
  dealership_id?: number;
  phone?: string;
  has_telegram?: 'connected' | 'not_connected';
  per_page?: number;
  page?: number;
}

export const usersApi = {
  // Получить список пользователей
  getUsers: async (filters?: UsersFilters): Promise<PaginatedResponse<User>> => {
    const response = await apiClient.get<PaginatedResponse<User>>('/users', {
      params: filters,
    });
    return response.data;
  },

  // Получить пользователя по ID
  getUser: async (id: number): Promise<User> => {
    const response = await apiClient.get<User>(`/users/${id}`);
    return response.data;
  },

  // Создать пользователя (только Manager/Owner)
  createUser: async (data: CreateUserRequest): Promise<{ data: User }> => {
    const response = await apiClient.post<{ data: User }>('/users', data);
    return response.data;
  },

  // Обновить пользователя (только Manager/Owner)
  updateUser: async (id: number, data: UpdateUserRequest): Promise<{ data: User }> => {
    const response = await apiClient.put<{ data: User }>(`/users/${id}`, data);
    return response.data;
  },

  // Удалить пользователя (только Manager/Owner)
  deleteUser: async (id: number): Promise<void> => {
    await apiClient.delete(`/users/${id}`);
  },

  // Проверить статус пользователя
  getUserStatus: async (id: number): Promise<{ is_active: boolean }> => {
    const response = await apiClient.get<{ is_active: boolean }>(`/users/${id}/status`);
    return response.data;
  },
};
```

### Шаг 2: Users Hook (React Query)

**`src/hooks/useUsers.ts`**

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usersApi, CreateUserRequest, UpdateUserRequest, UsersFilters } from '../api/users';
import { useAuthStore } from '../stores/authStore';

export const useUsers = (filters?: UsersFilters) => {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => usersApi.getUsers(filters),
  });
};

export const useUser = (id: number) => {
  return useQuery({
    queryKey: ['user', id],
    queryFn: () => usersApi.getUser(id),
    enabled: !!id,
  });
};

export const useCreateUser = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateUserRequest) => usersApi.createUser(data),
    onSuccess: () => {
      // Обновляем список пользователей после создания
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
};

export const useUpdateUser = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateUserRequest }) =>
      usersApi.updateUser(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      queryClient.invalidateQueries({ queryKey: ['user', variables.id] });
    },
  });
};

export const useDeleteUser = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => usersApi.deleteUser(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
};

// Hook для проверки прав
export const usePermissions = () => {
  const { user } = useAuthStore();

  const canCreateUsers = user?.role === 'manager' || user?.role === 'owner';
  const canEditUsers = user?.role === 'manager' || user?.role === 'owner';
  const canDeleteUsers = user?.role === 'manager' || user?.role === 'owner';
  const canManageTasks = user?.role === 'manager' || user?.role === 'owner';
  const canManageSettings = user?.role === 'manager' || user?.role === 'owner';
  const isOwner = user?.role === 'owner';

  return {
    canCreateUsers,
    canEditUsers,
    canDeleteUsers,
    canManageTasks,
    canManageSettings,
    isOwner,
    role: user?.role,
  };
};
```

### Шаг 3: User Form компонент

**`src/components/users/UserForm.tsx`**

```typescript
import React, { useState } from 'react';
import { useCreateUser, useUpdateUser } from '../../hooks/useUsers';
import { User } from '../../types/user';

interface UserFormProps {
  user?: User;
  onSuccess?: () => void;
  onCancel?: () => void;
}

export const UserForm: React.FC<UserFormProps> = ({ user, onSuccess, onCancel }) => {
  const [formData, setFormData] = useState({
    login: user?.login || '',
    password: '',
    full_name: user?.full_name || '',
    phone: user?.phone || '',
    role: user?.role || 'employee',
    dealership_id: user?.dealership_id || undefined,
  });

  const createUser = useCreateUser();
  const updateUser = useUpdateUser();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      if (user) {
        // Обновление существующего пользователя
        await updateUser.mutateAsync({
          id: user.id,
          data: {
            password: formData.password || undefined,
            full_name: formData.full_name,
            phone_number: formData.phone,
            role: formData.role,
            dealership_id: formData.dealership_id,
          },
        });
      } else {
        // Создание нового пользователя
        await createUser.mutateAsync({
          login: formData.login,
          password: formData.password,
          full_name: formData.full_name,
          phone: formData.phone,
          role: formData.role as any,
          dealership_id: formData.dealership_id,
        });
      }

      onSuccess?.();
    } catch (error: any) {
      console.error('Error saving user:', error);
      alert(error.response?.data?.message || 'Ошибка сохранения пользователя');
    }
  };

  return (
    <form onSubmit={handleSubmit} className="user-form">
      <div className="form-group">
        <label>Логин *</label>
        <input
          type="text"
          value={formData.login}
          onChange={(e) => setFormData({ ...formData, login: e.target.value })}
          required
          disabled={!!user} // Нельзя изменить логин
          minLength={4}
        />
      </div>

      <div className="form-group">
        <label>Пароль {!user && '*'}</label>
        <input
          type="password"
          value={formData.password}
          onChange={(e) => setFormData({ ...formData, password: e.target.value })}
          required={!user}
          minLength={8}
          placeholder={user ? 'Оставьте пустым, чтобы не менять' : ''}
        />
        <small>Минимум 8 символов, включая заглавные буквы, строчные буквы и цифры</small>
      </div>

      <div className="form-group">
        <label>Полное имя *</label>
        <input
          type="text"
          value={formData.full_name}
          onChange={(e) => setFormData({ ...formData, full_name: e.target.value })}
          required
          minLength={2}
        />
      </div>

      <div className="form-group">
        <label>Телефон *</label>
        <input
          type="tel"
          value={formData.phone}
          onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
          required
          placeholder="+79001234567"
        />
      </div>

      <div className="form-group">
        <label>Роль *</label>
        <select
          value={formData.role}
          onChange={(e) => setFormData({ ...formData, role: e.target.value })}
          required
        >
          <option value="employee">Сотрудник</option>
          <option value="observer">Наблюдатель</option>
          <option value="manager">Управляющий</option>
          <option value="owner">Владелец</option>
        </select>
      </div>

      <div className="form-actions">
        <button type="submit" disabled={createUser.isPending || updateUser.isPending}>
          {createUser.isPending || updateUser.isPending ? 'Сохранение...' : 'Сохранить'}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel}>
            Отмена
          </button>
        )}
      </div>
    </form>
  );
};
```

### Шаг 4: Users List компонент

**`src/components/users/UserList.tsx`**

```typescript
import React, { useState } from 'react';
import { useUsers, useDeleteUser } from '../../hooks/useUsers';
import { usePermissions } from '../../hooks/useUsers';

export const UserList: React.FC = () => {
  const [filters, setFilters] = useState({
    search: '',
    role: '',
    page: 1,
  });

  const { data, isLoading, error } = useUsers(filters);
  const deleteUser = useDeleteUser();
  const { canDeleteUsers } = usePermissions();

  const handleDelete = async (id: number, name: string) => {
    if (!confirm(`Удалить пользователя ${name}?`)) return;

    try {
      await deleteUser.mutateAsync(id);
      alert('Пользователь удален');
    } catch (error: any) {
      alert(error.response?.data?.message || 'Ошибка удаления');
    }
  };

  if (isLoading) return <div>Загрузка...</div>;
  if (error) return <div>Ошибка: {error.message}</div>;

  return (
    <div className="user-list">
      {/* Фильтры */}
      <div className="filters">
        <input
          type="text"
          placeholder="Поиск по имени или логину"
          value={filters.search}
          onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
        />

        <select
          value={filters.role}
          onChange={(e) => setFilters({ ...filters, role: e.target.value, page: 1 })}
        >
          <option value="">Все роли</option>
          <option value="employee">Сотрудник</option>
          <option value="observer">Наблюдатель</option>
          <option value="manager">Управляющий</option>
          <option value="owner">Владелец</option>
        </select>
      </div>

      {/* Таблица пользователей */}
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Логин</th>
            <th>Имя</th>
            <th>Роль</th>
            <th>Телефон</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          {data?.data.map((user) => (
            <tr key={user.id}>
              <td>{user.id}</td>
              <td>{user.login}</td>
              <td>{user.full_name}</td>
              <td>{user.role}</td>
              <td>{user.phone}</td>
              <td>
                <button onClick={() => console.log('Edit', user.id)}>
                  Редактировать
                </button>
                {canDeleteUsers && (
                  <button
                    onClick={() => handleDelete(user.id, user.full_name)}
                    disabled={deleteUser.isPending}
                  >
                    Удалить
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Пагинация */}
      {data && data.last_page > 1 && (
        <div className="pagination">
          <button
            disabled={filters.page === 1}
            onClick={() => setFilters({ ...filters, page: filters.page - 1 })}
          >
            Назад
          </button>
          <span>
            Страница {data.current_page} из {data.last_page}
          </span>
          <button
            disabled={filters.page === data.last_page}
            onClick={() => setFilters({ ...filters, page: filters.page + 1 })}
          >
            Вперед
          </button>
        </div>
      )}
    </div>
  );
};
```

---

## Управление задачами

### Шаг 1: Task Types

**`src/types/task.ts`**

```typescript
export type TaskRecurrence = 'none' | 'daily' | 'weekly' | 'monthly';
export type TaskStatus = 'pending' | 'acknowledged' | 'completed' | 'overdue' | 'postponed';
export type TaskType = 'individual' | 'group';
export type ResponseType = 'acknowledge' | 'complete';

export interface Task {
  id: number;
  title: string;
  description: string | null;
  comment: string | null;
  task_type: TaskType;
  response_type: ResponseType;
  recurrence: TaskRecurrence;
  recurrence_time: string | null;
  recurrence_day_of_week: number | null;
  recurrence_day_of_month: number | null;
  appear_date: string | null;
  deadline: string | null;
  status: TaskStatus;
  dealership_id: number;
  created_by: number;
  tags: string[];
  created_at: string;
  updated_at: string;

  // Связи
  creator?: {
    id: number;
    full_name: string;
  };
  assignments?: Array<{
    id: number;
    user_id: number;
    status: string;
  }>;
}

export interface CreateTaskRequest {
  title: string;
  description?: string;
  comment?: string;
  task_type: TaskType;
  response_type: ResponseType;
  recurrence?: TaskRecurrence;
  recurrence_time?: string;
  recurrence_day_of_week?: number;
  recurrence_day_of_month?: number;
  appear_date?: string;
  deadline?: string;
  dealership_id: number;
  tags?: string[];
  assignments: number[]; // user IDs
}
```

### Шаг 2: Tasks API

**`src/api/tasks.ts`**

```typescript
import apiClient from './client';
import { Task, CreateTaskRequest } from '../types/task';
import { PaginatedResponse } from '../types/api';

export interface TasksFilters {
  search?: string;
  status?: string;
  recurrence?: string;
  task_type?: string;
  response_type?: string;
  dealership_id?: number;
  created_by?: number;
  per_page?: number;
  page?: number;
}

export const tasksApi = {
  // Получить список задач
  getTasks: async (filters?: TasksFilters): Promise<PaginatedResponse<Task>> => {
    const response = await apiClient.get<PaginatedResponse<Task>>('/tasks', {
      params: filters,
    });
    return response.data;
  },

  // Получить задачу по ID
  getTask: async (id: number): Promise<Task> => {
    const response = await apiClient.get<Task>(`/tasks/${id}`);
    return response.data;
  },

  // Получить отложенные задачи
  getPostponedTasks: async (): Promise<Task[]> => {
    const response = await apiClient.get<Task[]>('/tasks/postponed');
    return response.data;
  },

  // Создать задачу (только Manager/Owner)
  createTask: async (data: CreateTaskRequest): Promise<{ data: Task }> => {
    const response = await apiClient.post<{ data: Task }>('/tasks', data);
    return response.data;
  },

  // Обновить задачу (только Manager/Owner)
  updateTask: async (id: number, data: Partial<CreateTaskRequest>): Promise<{ data: Task }> => {
    const response = await apiClient.put<{ data: Task }>(`/tasks/${id}`, data);
    return response.data;
  },

  // Удалить задачу (только Manager/Owner)
  deleteTask: async (id: number): Promise<void> => {
    await apiClient.delete(`/tasks/${id}`);
  },
};
```

### Шаг 3: Tasks Hook

**`src/hooks/useTasks.ts`**

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { tasksApi, TasksFilters, CreateTaskRequest } from '../api/tasks';

export const useTasks = (filters?: TasksFilters) => {
  return useQuery({
    queryKey: ['tasks', filters],
    queryFn: () => tasksApi.getTasks(filters),
  });
};

export const useTask = (id: number) => {
  return useQuery({
    queryKey: ['task', id],
    queryFn: () => tasksApi.getTask(id),
    enabled: !!id,
  });
};

export const useCreateTask = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateTaskRequest) => tasksApi.createTask(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tasks'] });
    },
  });
};

export const useUpdateTask = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<CreateTaskRequest> }) =>
      tasksApi.updateTask(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['tasks'] });
      queryClient.invalidateQueries({ queryKey: ['task', variables.id] });
    },
  });
};

export const useDeleteTask = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => tasksApi.deleteTask(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tasks'] });
    },
  });
};
```

### Шаг 4: Task Form компонент

**`src/components/tasks/TaskForm.tsx`**

```typescript
import React, { useState } from 'react';
import { useCreateTask } from '../../hooks/useTasks';
import { CreateTaskRequest } from '../types/task';

interface TaskFormProps {
  onSuccess?: () => void;
  onCancel?: () => void;
}

export const TaskForm: React.FC<TaskFormProps> = ({ onSuccess, onCancel }) => {
  const [formData, setFormData] = useState<Partial<CreateTaskRequest>>({
    title: '',
    description: '',
    comment: '',
    task_type: 'individual',
    response_type: 'acknowledge',
    recurrence: 'none',
    dealership_id: 1,
    tags: [],
    assignments: [],
  });

  const createTask = useCreateTask();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      await createTask.mutateAsync(formData as CreateTaskRequest);
      onSuccess?.();
    } catch (error: any) {
      alert(error.response?.data?.message || 'Ошибка создания задачи');
    }
  };

  return (
    <form onSubmit={handleSubmit} className="task-form">
      <div className="form-group">
        <label>Название *</label>
        <input
          type="text"
          value={formData.title}
          onChange={(e) => setFormData({ ...formData, title: e.target.value })}
          required
        />
      </div>

      <div className="form-group">
        <label>Описание</label>
        <textarea
          value={formData.description}
          onChange={(e) => setFormData({ ...formData, description: e.target.value })}
        />
      </div>

      <div className="form-group">
        <label>Тип задачи *</label>
        <select
          value={formData.task_type}
          onChange={(e) => setFormData({ ...formData, task_type: e.target.value as any })}
        >
          <option value="individual">Индивидуальная</option>
          <option value="group">Групповая</option>
        </select>
      </div>

      <div className="form-group">
        <label>Тип ответа *</label>
        <select
          value={formData.response_type}
          onChange={(e) => setFormData({ ...formData, response_type: e.target.value as any })}
        >
          <option value="acknowledge">Уведомление (OK)</option>
          <option value="complete">Выполнение (Выполнено/Перенести)</option>
        </select>
      </div>

      <div className="form-group">
        <label>Повторяемость *</label>
        <select
          value={formData.recurrence}
          onChange={(e) => setFormData({ ...formData, recurrence: e.target.value as any })}
        >
          <option value="none">Нет</option>
          <option value="daily">Ежедневно</option>
          <option value="weekly">Еженедельно</option>
          <option value="monthly">Ежемесячно</option>
        </select>
      </div>

      {/* Дополнительные поля для повторяющихся задач */}
      {formData.recurrence !== 'none' && (
        <>
          <div className="form-group">
            <label>Время выполнения (HH:MM) *</label>
            <input
              type="time"
              value={formData.recurrence_time || ''}
              onChange={(e) => setFormData({ ...formData, recurrence_time: e.target.value })}
              required
            />
          </div>

          {formData.recurrence === 'weekly' && (
            <div className="form-group">
              <label>День недели *</label>
              <select
                value={formData.recurrence_day_of_week || ''}
                onChange={(e) => setFormData({ ...formData, recurrence_day_of_week: Number(e.target.value) })}
                required
              >
                <option value="">Выберите день</option>
                <option value="1">Понедельник</option>
                <option value="2">Вторник</option>
                <option value="3">Среда</option>
                <option value="4">Четверг</option>
                <option value="5">Пятница</option>
                <option value="6">Суббота</option>
                <option value="7">Воскресенье</option>
              </select>
            </div>
          )}

          {formData.recurrence === 'monthly' && (
            <div className="form-group">
              <label>День месяца *</label>
              <select
                value={formData.recurrence_day_of_month || ''}
                onChange={(e) => setFormData({ ...formData, recurrence_day_of_month: Number(e.target.value) })}
                required
              >
                <option value="">Выберите день</option>
                <option value="-1">Первый день месяца</option>
                <option value="-2">Последний день месяца</option>
                {Array.from({ length: 31 }, (_, i) => i + 1).map((day) => (
                  <option key={day} value={day}>
                    {day} число
                  </option>
                ))}
              </select>
            </div>
          )}
        </>
      )}

      <div className="form-group">
        <label>Дедлайн</label>
        <input
          type="datetime-local"
          value={formData.deadline || ''}
          onChange={(e) => setFormData({ ...formData, deadline: e.target.value })}
        />
      </div>

      <div className="form-actions">
        <button type="submit" disabled={createTask.isPending}>
          {createTask.isPending ? 'Создание...' : 'Создать задачу'}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel}>
            Отмена
          </button>
        )}
      </div>
    </form>
  );
};
```

---

## Управление сменами

### Шаг 1: Shift Types

**`src/types/shift.ts`**

```typescript
export type ShiftStatus = 'open' | 'closed';

export interface Shift {
  id: number;
  user_id: number;
  dealership_id: number;
  status: ShiftStatus;
  opened_at: string;
  closed_at: string | null;
  scheduled_start: string | null;
  scheduled_end: string | null;
  is_late: boolean;
  late_minutes: number | null;
  opened_photo: string | null;
  closed_photo: string | null;
  replacement_id: number | null;
  replacement_reason: string | null;
  created_at: string;
  updated_at: string;

  // Связи
  user?: {
    id: number;
    full_name: string;
  };
  replacement?: {
    id: number;
    full_name: string;
  };
}
```

### Шаг 2: Shifts API

**`src/api/shifts.ts`**

```typescript
import apiClient from './client';
import { Shift } from '../types/shift';
import { PaginatedResponse } from '../types/api';

export interface ShiftsFilters {
  user_id?: number;
  dealership_id?: number;
  status?: 'open' | 'closed';
  is_late?: boolean;
  per_page?: number;
  page?: number;
}

export interface ShiftStatistics {
  total_shifts: number;
  open_shifts: number;
  closed_shifts: number;
  late_shifts: number;
  average_late_minutes: number;
}

export const shiftsApi = {
  // Получить список смен
  getShifts: async (filters?: ShiftsFilters): Promise<PaginatedResponse<Shift>> => {
    const response = await apiClient.get<PaginatedResponse<Shift>>('/shifts', {
      params: filters,
    });
    return response.data;
  },

  // Получить текущие смены
  getCurrentShifts: async (): Promise<Shift[]> => {
    const response = await apiClient.get<Shift[]>('/shifts/current');
    return response.data;
  },

  // Получить статистику
  getStatistics: async (filters?: { dealership_id?: number }): Promise<ShiftStatistics> => {
    const response = await apiClient.get<ShiftStatistics>('/shifts/statistics', {
      params: filters,
    });
    return response.data;
  },

  // Получить смену по ID
  getShift: async (id: number): Promise<Shift> => {
    const response = await apiClient.get<Shift>(`/shifts/${id}`);
    return response.data;
  },
};
```

---

## Настройки

### Шаг 1: Settings API

**`src/api/settings.ts`**

```typescript
import apiClient from './client';

export interface Setting {
  id: number;
  dealership_id: number | null;
  key: string;
  value: string;
  type: 'string' | 'integer' | 'boolean' | 'json' | 'time';
  description: string | null;
  created_at: string;
  updated_at: string;
}

export interface ShiftConfig {
  shift_1_start_time: string;
  shift_1_end_time: string;
  shift_2_start_time: string;
  shift_2_end_time: string;
  late_tolerance_minutes: number;
}

export const settingsApi = {
  // Получить все настройки
  getSettings: async (dealership_id?: number): Promise<Setting[]> => {
    const response = await apiClient.get<Setting[]>('/settings', {
      params: { dealership_id },
    });
    return response.data;
  },

  // Получить настройку по ключу
  getSetting: async (key: string, dealership_id?: number): Promise<Setting> => {
    const response = await apiClient.get<Setting>(`/settings/${key}`, {
      params: { dealership_id },
    });
    return response.data;
  },

  // Получить настройки смен
  getShiftConfig: async (dealership_id?: number): Promise<ShiftConfig> => {
    const response = await apiClient.get<ShiftConfig>('/settings/shift-config', {
      params: { dealership_id },
    });
    return response.data;
  },

  // Обновить настройки смен (только Manager/Owner)
  updateShiftConfig: async (data: Partial<ShiftConfig>, dealership_id?: number): Promise<void> => {
    await apiClient.post('/settings/shift-config', {
      ...data,
      dealership_id,
    });
  },

  // Создать настройку (только Manager/Owner)
  createSetting: async (data: Omit<Setting, 'id' | 'created_at' | 'updated_at'>): Promise<Setting> => {
    const response = await apiClient.post<Setting>('/settings', data);
    return response.data;
  },

  // Обновить настройку (только Manager/Owner)
  updateSetting: async (id: number, data: Partial<Setting>): Promise<Setting> => {
    const response = await apiClient.put<Setting>(`/settings/${id}`, data);
    return response.data;
  },

  // Удалить настройку (только Manager/Owner)
  deleteSetting: async (id: number): Promise<void> => {
    await apiClient.delete(`/settings/${id}`);
  },
};
```

---

## Dashboard

### Dashboard API

**`src/api/dashboard.ts`**

```typescript
import apiClient from './client';

export interface DashboardData {
  total_users: number;
  active_users: number;
  total_tasks: number;
  active_tasks: number;
  completed_tasks: number;
  overdue_tasks: number;
  open_shifts: number;
  late_shifts_today: number;
  recent_tasks: Array<{
    id: number;
    title: string;
    status: string;
    created_at: string;
  }>;
}

export const dashboardApi = {
  getData: async (dealership_id?: number): Promise<DashboardData> => {
    const response = await apiClient.get<DashboardData>('/dashboard', {
      params: { dealership_id },
    });
    return response.data;
  },
};
```

---

## Обработка ошибок

### Глобальный обработчик ошибок

**`src/utils/errorHandler.ts`**

```typescript
import { AxiosError } from 'axios';
import { ApiError } from '../types/api';

export const handleApiError = (error: unknown): string => {
  if (error instanceof AxiosError) {
    const apiError = error.response?.data as ApiError;

    // 401 - Неавторизован
    if (error.response?.status === 401) {
      return 'Необходима авторизация';
    }

    // 403 - Недостаточно прав
    if (error.response?.status === 403) {
      const roles = apiError.required_roles?.join(', ') || '';
      return `Недостаточно прав. Требуется роль: ${roles}`;
    }

    // 422 - Ошибка валидации
    if (error.response?.status === 422 && apiError.errors) {
      const errors = Object.values(apiError.errors).flat();
      return errors.join('. ');
    }

    // 404 - Не найдено
    if (error.response?.status === 404) {
      return 'Ресурс не найден';
    }

    // 500 - Ошибка сервера
    if (error.response?.status === 500) {
      return 'Ошибка сервера. Попробуйте позже';
    }

    return apiError.message || 'Произошла ошибка';
  }

  return 'Неизвестная ошибка';
};
```

### Toast уведомления

**`src/hooks/useToast.ts`**

```typescript
import { useState, useCallback } from 'react';

type ToastType = 'success' | 'error' | 'info' | 'warning';

interface Toast {
  id: string;
  message: string;
  type: ToastType;
}

export const useToast = () => {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const showToast = useCallback((message: string, type: ToastType = 'info') => {
    const id = Date.now().toString();
    setToasts((prev) => [...prev, { id, message, type }]);

    // Автоматически удаляем через 5 секунд
    setTimeout(() => {
      setToasts((prev) => prev.filter((toast) => toast.id !== id));
    }, 5000);
  }, []);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((toast) => toast.id !== id));
  }, []);

  return { toasts, showToast, removeToast };
};
```

---

## Лучшие практики

### 1. Безопасность

```typescript
// ✅ Всегда храните токен в localStorage или httpOnly cookie
localStorage.setItem('auth_token', token);

// ✅ Проверяйте роли перед отображением UI
const { canCreateUsers } = usePermissions();
{canCreateUsers && <button>Создать пользователя</button>}

// ✅ Обрабатывайте 403 ошибки
if (error.response?.status === 403) {
  showToast('Недостаточно прав', 'error');
}
```

### 2. Производительность

```typescript
// ✅ Используйте React Query для кеширования
const { data, isLoading } = useUsers({ page: 1 });

// ✅ Добавляйте debounce для поиска
const [search, setSearch] = useState('');
const debouncedSearch = useDebounce(search, 500);

useEffect(() => {
  // Запрос с задержкой
}, [debouncedSearch]);
```

### 3. User Experience

```typescript
// ✅ Показывайте loading состояния
{isLoading && <Spinner />}

// ✅ Обрабатывайте ошибки с понятными сообщениями
{error && <ErrorMessage message={handleApiError(error)} />}

// ✅ Подтверждайте деструктивные действия
const handleDelete = () => {
  if (confirm('Вы уверены?')) {
    deleteUser(id);
  }
};
```

### 4. Code Organization

```typescript
// ✅ Разделяйте логику на слои
// - API layer (api/)
// - Business logic (hooks/)
// - UI components (components/)
// - State management (stores/)

// ✅ Используйте TypeScript для типизации
interface User {
  id: number;
  name: string;
}

// ✅ Создавайте переиспользуемые компоненты
const Button = ({ children, onClick }: ButtonProps) => { ... };
```

### 5. Testing

```typescript
// Тесты для компонентов
test('LoginForm submits correctly', async () => {
  render(<LoginForm />);

  await userEvent.type(screen.getByLabelText('Логин'), 'testuser');
  await userEvent.type(screen.getByLabelText('Пароль'), 'Password123');
  await userEvent.click(screen.getByRole('button', { name: 'Войти' }));

  expect(authApi.login).toHaveBeenCalledWith({
    login: 'testuser',
    password: 'Password123',
  });
});
```

---

## Дополнительные ресурсы

### Документация

- **Swagger**: Полная документация API в `swagger.yaml`
- **ROLE_HIERARCHY.md**: Подробности о ролях и правах доступа
- **RECURRING_TASKS.md**: Руководство по повторяющимся задачам

### Полезные библиотеки

- **Axios**: HTTP клиент - https://axios-http.com/
- **React Query**: Управление серверным состоянием - https://tanstack.com/query
- **Zustand**: State management - https://zustand-demo.pmnd.rs/
- **React Hook Form**: Работа с формами - https://react-hook-form.com/
- **date-fns**: Работа с датами - https://date-fns.org/

### Примеры кода

См. папку `examples/` в проекте для готовых примеров интеграции.

---

## FAQ

**Q: Как создать первого пользователя, если нет endpoint регистрации?**

A: Первый пользователь (owner) должен быть создан через Laravel Seeder или напрямую в базе данных. После этого owner может создавать других пользователей через API.

**Q: Почему я получаю 403 ошибку?**

A: Проверьте что ваша роль соответствует требованиям endpoint. Например, только Manager и Owner могут создавать пользователей.

**Q: Как обновить роль текущего пользователя?**

A: Используйте `GET /session/current` для получения актуальной роли после изменений.

**Q: Можно ли использовать другой фронтенд фреймворк?**

A: Да, это REST API и вы можете использовать любой фронтенд: Angular, Svelte, Vue, или даже мобильное приложение.

---

**© 2025 TaskMate. Все права защищены.**
