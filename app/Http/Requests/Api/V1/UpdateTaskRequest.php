<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для обновления задачи.
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для обновления задачи.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'sometimes|required|string',
            'deadline' => 'sometimes|required|string',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly',
            'recurrence_time' => 'nullable|date_format:H:i',
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
            'notification_settings' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
        ];
    }

    /**
     * Сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название задачи обязательно',
            'title.max' => 'Название задачи не может превышать 255 символов',
            'dealership_id.exists' => 'Автосалон не найден',
            'appear_date.required' => 'Дата появления задачи обязательна',
            'deadline.required' => 'Дедлайн обязателен',
            'recurrence.in' => 'Некорректный тип повторения',
            'recurrence_time.date_format' => 'Неверный формат времени (ожидается HH:MM)',
            'recurrence_day_of_week.min' => 'День недели должен быть от 1 (Пн) до 7 (Вс)',
            'recurrence_day_of_week.max' => 'День недели должен быть от 1 (Пн) до 7 (Вс)',
            'recurrence_day_of_month.min' => 'День месяца должен быть от -2 до 31',
            'recurrence_day_of_month.max' => 'День месяца должен быть от -2 до 31',
            'task_type.required' => 'Тип задачи обязателен',
            'task_type.in' => 'Некорректный тип задачи',
            'response_type.required' => 'Тип ответа обязателен',
            'response_type.in' => 'Некорректный тип ответа',
            'assignments.*.exists' => 'Пользователь не найден',
            'priority.in' => 'Некорректный приоритет',
        ];
    }

    /**
     * Дополнительная валидация после прохождения базовых правил.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Получаем текущую задачу для проверки recurrence полей
            $task = $this->route('task') instanceof Task
                ? $this->route('task')
                : Task::find($this->route('task'));

            $recurrence = $this->input('recurrence') ?? $task?->recurrence;

            if (!empty($recurrence) && $recurrence !== 'none') {
                $recurrenceTime = $this->input('recurrence_time') ?? $task?->recurrence_time;
                $recurrenceDayOfWeek = $this->input('recurrence_day_of_week') ?? $task?->recurrence_day_of_week;
                $recurrenceDayOfMonth = $this->input('recurrence_day_of_month') ?? $task?->recurrence_day_of_month;

                switch ($recurrence) {
                    case 'daily':
                        if (empty($recurrenceTime)) {
                            $validator->errors()->add(
                                'recurrence_time',
                                'Для ежедневных задач необходимо указать время'
                            );
                        }
                        break;
                    case 'weekly':
                        if (empty($recurrenceDayOfWeek)) {
                            $validator->errors()->add(
                                'recurrence_day_of_week',
                                'Для еженедельных задач необходимо указать день недели'
                            );
                        }
                        break;
                    case 'monthly':
                        if (empty($recurrenceDayOfMonth)) {
                            $validator->errors()->add(
                                'recurrence_day_of_month',
                                'Для ежемесячных задач необходимо указать число месяца'
                            );
                        }
                        break;
                }
            }
        });
    }

    /**
     * Обработка неуспешной валидации.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
