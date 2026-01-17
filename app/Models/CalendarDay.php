<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Модель для хранения выходных и рабочих дней.
 *
 * Позволяет настраивать календарь как глобально (dealership_id = null),
 * так и для конкретного автосалона. При проверке используется fallback:
 * сначала проверяются настройки салона, затем глобальные.
 */
class CalendarDay extends Model
{
    use HasFactory;

    protected $table = 'calendar_days';

    protected $fillable = [
        'dealership_id',
        'date',
        'type',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Связь с автосалоном.
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    /**
     * Проверяет, является ли указанная дата выходным днём.
     *
     * Логика: сначала проверяем настройки для конкретного dealership,
     * если не найдено — проверяем глобальные настройки (dealership_id = null).
     */
    public static function isHoliday(Carbon $date, ?int $dealershipId = null): bool
    {
        $dateStr = $date->toDateString();

        // Ищем настройку для конкретного dealership
        if ($dealershipId !== null) {
            $record = self::where('date', $dateStr)
                ->where('dealership_id', $dealershipId)
                ->first();

            if ($record !== null) {
                return $record->type === 'holiday';
            }
        }

        // Fallback на глобальные настройки
        $globalRecord = self::where('date', $dateStr)
            ->whereNull('dealership_id')
            ->first();

        if ($globalRecord !== null) {
            return $globalRecord->type === 'holiday';
        }

        // По умолчанию всё рабочие дни
        return false;
    }

    /**
     * Проверяет, является ли указанная дата рабочим днём.
     */
    public static function isWorkday(Carbon $date, ?int $dealershipId = null): bool
    {
        return !self::isHoliday($date, $dealershipId);
    }

    /**
     * Массовая установка выходных по дням недели.
     *
     * @param int $year Год
     * @param array $weekdays Дни недели (1=Пн, 7=Вс)
     * @param int|null $dealershipId ID автосалона или null для глобальных
     * @param string $type Тип дня: 'holiday' или 'workday'
     */
    public static function setWeekdaysForYear(
        int $year,
        array $weekdays,
        ?int $dealershipId = null,
        string $type = 'holiday'
    ): int {
        $startDate = Carbon::createFromDate($year, 1, 1);
        $endDate = Carbon::createFromDate($year, 12, 31);
        $count = 0;

        $records = [];
        $now = Carbon::now();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $weekdays)) {
                $records[] = [
                    'dealership_id' => $dealershipId,
                    'date' => $date->toDateString(),
                    'type' => $type,
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;
            }
        }

        // Используем upsert для добавления/обновления
        if (!empty($records)) {
            foreach (array_chunk($records, 100) as $chunk) {
                DB::table('calendar_days')->upsert(
                    $chunk,
                    ['dealership_id', 'date'],
                    ['type', 'description', 'updated_at']
                );
            }
        }

        return $count;
    }

    /**
     * Получить календарь на год.
     *
     * @param int $year Год
     * @param int|null $dealershipId ID автосалона или null для глобальных
     * @return Collection Коллекция записей CalendarDay
     */
    public static function getYearCalendar(int $year, ?int $dealershipId = null): Collection
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        $query = self::whereBetween('date', [$startDate, $endDate]);

        if ($dealershipId !== null) {
            // Получаем и глобальные, и специфичные для салона
            $query->where(function ($q) use ($dealershipId) {
                $q->where('dealership_id', $dealershipId)
                    ->orWhereNull('dealership_id');
            });
        } else {
            $query->whereNull('dealership_id');
        }

        return $query->orderBy('date')->get();
    }

    /**
     * Получить только выходные дни за год.
     */
    public static function getHolidaysForYear(int $year, ?int $dealershipId = null): Collection
    {
        return self::getYearCalendar($year, $dealershipId)
            ->where('type', 'holiday');
    }

    /**
     * Очистить все записи за год.
     */
    public static function clearYear(int $year, ?int $dealershipId = null): int
    {
        $startDate = Carbon::createFromDate($year, 1, 1)->toDateString();
        $endDate = Carbon::createFromDate($year, 12, 31)->toDateString();

        $query = self::whereBetween('date', [$startDate, $endDate]);

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        return $query->delete();
    }

    /**
     * Устанавливает или удаляет конкретный день.
     */
    public static function setDay(
        Carbon $date,
        string $type,
        ?int $dealershipId = null,
        ?string $description = null
    ): self {
        return self::updateOrCreate(
            [
                'dealership_id' => $dealershipId,
                'date' => $date->toDateString(),
            ],
            [
                'type' => $type,
                'description' => $description,
            ]
        );
    }

    /**
     * Удаляет настройку для конкретного дня (возвращает к дефолту — рабочий).
     */
    public static function removeDay(Carbon $date, ?int $dealershipId = null): bool
    {
        return self::where('date', $date->toDateString())
            ->where('dealership_id', $dealershipId)
            ->delete() > 0;
    }

    /**
     * Конвертация в API массив.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'type' => $this->type,
            'description' => $this->description,
            'dealership_id' => $this->dealership_id,
        ];
    }
}
