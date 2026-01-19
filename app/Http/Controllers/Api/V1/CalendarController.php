<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarDay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API для управления календарём выходных/рабочих дней.
 */
class CalendarController extends Controller
{
    /**
     * Получить календарь на год.
     *
     * GET /api/v1/calendar/{year}
     */
    public function index(Request $request, int $year): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        $calendar = CalendarDay::getYearCalendar($year, $dealershipId);

        // Группируем по месяцам для удобства
        $grouped = [];
        foreach ($calendar as $day) {
            $month = Carbon::parse($day->date)->month;
            $grouped[$month][] = $day->toApiArray();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'dealership_id' => $dealershipId,
                'months' => $grouped,
                'holidays_count' => $calendar->where('type', 'holiday')->count(),
            ],
        ]);
    }

    /**
     * Получить все выходные за год (только даты).
     *
     * GET /api/v1/calendar/{year}/holidays
     */
    public function holidays(Request $request, int $year): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        $holidays = CalendarDay::getHolidaysForYear($year, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'dealership_id' => $dealershipId,
                'dates' => $holidays->pluck('date')->map(fn($d) => $d->toDateString())->values(),
                'count' => $holidays->count(),
            ],
        ]);
    }

    /**
     * Установить/обновить конкретный день.
     *
     * PUT /api/v1/calendar/{date}
     */
    public function update(Request $request, string $date): JsonResponse
    {
        $validator = Validator::make(
            array_merge($request->all(), ['date' => $date]),
            [
                'date' => 'required|date_format:Y-m-d',
                'type' => 'required|in:holiday,workday',
                'description' => 'nullable|string|max:255',
                'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $carbonDate = Carbon::parse($data['date']);
        $dealershipId = $data['dealership_id'] ?? null;

        $calendarDay = CalendarDay::setDay(
            $carbonDate,
            $data['type'],
            $dealershipId,
            $data['description'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Calendar day updated',
            'data' => $calendarDay->toApiArray(),
        ]);
    }

    /**
     * Удалить настройку дня (вернуть к дефолту — рабочий).
     *
     * DELETE /api/v1/calendar/{date}
     */
    public function destroy(Request $request, string $date): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        try {
            $carbonDate = Carbon::parse($date);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format',
            ], 422);
        }

        $deleted = CalendarDay::removeDay($carbonDate, $dealershipId);

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Calendar day removed' : 'No record found',
            'data' => ['deleted' => $deleted],
        ]);
    }

    /**
     * Массовое обновление дней.
     *
     * POST /api/v1/calendar/bulk
     *
     * Поддерживаемые операции:
     * - set_weekdays: установить все указанные дни недели как выходные/рабочие
     * - set_dates: установить конкретные даты
     * - clear_year: очистить все записи за год
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'operation' => 'required|in:set_weekdays,set_dates,clear_year',
            'year' => 'required|integer|min:2020|max:2100',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',

            // Для set_weekdays
            'weekdays' => 'required_if:operation,set_weekdays|array',
            'weekdays.*' => 'integer|min:1|max:7',

            // Для set_dates
            'dates' => 'required_if:operation,set_dates|array',
            'dates.*' => 'date_format:Y-m-d',

            // Общие параметры
            'type' => 'required_if:operation,set_weekdays,set_dates|in:holiday,workday',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $dealershipId = $data['dealership_id'] ?? null;
        $year = (int) $data['year'];
        $affectedCount = 0;

        switch ($data['operation']) {
            case 'set_weekdays':
                $affectedCount = CalendarDay::setWeekdaysForYear(
                    $year,
                    $data['weekdays'],
                    $dealershipId,
                    $data['type']
                );
                break;

            case 'set_dates':
                foreach ($data['dates'] as $dateStr) {
                    CalendarDay::setDay(
                        Carbon::parse($dateStr),
                        $data['type'],
                        $dealershipId
                    );
                    $affectedCount++;
                }
                break;

            case 'clear_year':
                $affectedCount = CalendarDay::clearYear($year, $dealershipId);
                break;
        }

        return response()->json([
            'success' => true,
            'message' => "Bulk operation '{$data['operation']}' completed",
            'data' => [
                'operation' => $data['operation'],
                'year' => $year,
                'dealership_id' => $dealershipId,
                'affected_count' => $affectedCount,
            ],
        ]);
    }

    /**
     * Проверить, является ли дата выходным.
     *
     * GET /api/v1/calendar/check/{date}
     */
    public function check(Request $request, string $date): JsonResponse
    {
        $dealershipId = $request->query('dealership_id') !== null
            ? (int) $request->query('dealership_id')
            : null;

        try {
            $carbonDate = Carbon::parse($date);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format',
            ], 422);
        }

        $isHoliday = CalendarDay::isHoliday($carbonDate, $dealershipId);

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $carbonDate->toDateString(),
                'is_holiday' => $isHoliday,
                'is_workday' => !$isHoliday,
                'day_of_week' => $carbonDate->dayOfWeekIso,
                'dealership_id' => $dealershipId,
            ],
        ]);
    }
}
