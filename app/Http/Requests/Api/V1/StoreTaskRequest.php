<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для создания задачи.
 */
class StoreTaskRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для создания задачи.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'required|string',
            'deadline' => 'required|string',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly',
            'recurrence_time' => 'nullable|date_format:H:i',
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
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
            $recurrence = $this->input('recurrence');

            if (!empty($recurrence) && $recurrence !== 'none') {
                switch ($recurrence) {
                    case 'daily':
                        if (empty($this->input('recurrence_time'))) {
                            $validator->errors()->add(
                                'recurrence_time',
                                'Для ежедневных задач необходимо указать время'
                            );
                        }
                        break;
                    case 'weekly':
                        if (empty($this->input('recurrence_day_of_week'))) {
                            $validator->errors()->add(
                                'recurrence_day_of_week',
                                'Для еженедельных задач необходимо указать день недели'
                            );
                        }
                        break;
                    case 'monthly':
                        if (empty($this->input('recurrence_day_of_month'))) {
                            $validator->errors()->add(
                                'recurrence_day_of_month',
                                'Для ежемесячных задач необходимо указать число месяца'
                            );
                        }
                        break;
                }
            }

            // Валидация типа задачи и количества исполнителей
            $taskType = $this->input('task_type');
            $assignments = $this->input('assignments', []);
            $assignmentCount = is_array($assignments) ? count($assignments) : 0;

            // Групповая задача должна иметь хотя бы одного исполнителя
            if ($taskType === 'group' && $assignmentCount === 0) {
                $validator->errors()->add(
                    'assignments',
                    'Для групповой задачи необходимо указать хотя бы одного исполнителя'
                );
            }

            // Индивидуальная задача не может иметь более одного исполнителя
            if ($taskType === 'individual' && $assignmentCount > 1) {
                $validator->errors()->add(
                    'task_type',
                    'Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.'
                );
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
