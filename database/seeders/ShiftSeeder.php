<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftReplacement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Сидер для создания истории смен.
 *
 * Создаёт реалистичную историю смен для сотрудников:
 * - Закрытые смены за указанный период
 * - Смены с опозданиями
 * - Смены с замещениями
 * - Открытые смены на текущий день
 */
class ShiftSeeder extends Seeder
{
    /**
     * Количество дней истории.
     */
    public static int $historyDays = 30;

    /**
     * Причины замещения смен.
     */
    private const REPLACEMENT_REASONS = [
        'Болезнь',
        'Семейные обстоятельства',
        'Отпуск',
        'Личные причины',
        'Учёба',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Создание истории смен за ' . self::$historyDays . ' дней...');

        $dealerships = AutoDealership::all();

        if ($dealerships->isEmpty()) {
            $this->command->warn('Автосалоны не найдены. Пропуск создания смен.');
            return;
        }

        foreach ($dealerships as $dealership) {
            $this->createShiftsForDealership($dealership);
        }
    }

    /**
     * Создать смены для автосалона.
     */
    private function createShiftsForDealership(AutoDealership $dealership): void
    {
        $employees = User::where('dealership_id', $dealership->id)
            ->where('role', Role::EMPLOYEE)
            ->get();

        if ($employees->isEmpty()) {
            $this->command->warn("Сотрудники не найдены для {$dealership->name}. Пропуск.");
            return;
        }

        $today = Carbon::today('Asia/Yekaterinburg');
        $startDate = $today->copy()->subDays(self::$historyDays);

        $shiftsCreated = 0;
        $lateShifts = 0;
        $replacements = 0;

        // Создаём смены за каждый день
        $currentDate = $startDate->copy();
        while ($currentDate->lt($today)) {
            // Пропускаем выходные (суббота и воскресенье)
            if ($currentDate->isWeekend()) {
                $currentDate->addDay();
                continue;
            }

            foreach ($employees as $employee) {
                // 90% вероятность что сотрудник работал в этот день
                if (fake()->boolean(90)) {
                    $shift = $this->createClosedShift($employee, $dealership, $currentDate);
                    $shiftsCreated++;

                    // 10% смен с опозданием
                    if (fake()->boolean(10)) {
                        $shift->update([
                            'late_minutes' => fake()->numberBetween(5, 45),
                        ]);
                        $lateShifts++;
                    }
                }
            }

            $currentDate->addDay();
        }

        // Создаём замещения для некоторых смен
        $replacements = $this->createReplacements($dealership, $employees);

        // Создаём открытые смены на сегодня (будний день)
        $openShiftsCreated = 0;
        if (!$today->isWeekend()) {
            foreach ($employees as $employee) {
                // 80% сотрудников работают сегодня
                if (fake()->boolean(80)) {
                    $this->createOpenShift($employee, $dealership, $today);
                    $openShiftsCreated++;
                }
            }
        }

        $this->command->info(" - {$dealership->name}: {$shiftsCreated} закрытых смен, {$lateShifts} с опозданием, {$replacements} замещений, {$openShiftsCreated} открытых");
    }

    /**
     * Создать закрытую смену.
     */
    private function createClosedShift(User $employee, AutoDealership $dealership, Carbon $date): Shift
    {
        // Утренняя или вечерняя смена
        $isMorningShift = fake()->boolean(60);

        if ($isMorningShift) {
            $scheduledStart = $date->copy()->setTime(9, 0);
            $scheduledEnd = $date->copy()->setTime(14, 0);
        } else {
            $scheduledStart = $date->copy()->setTime(14, 0);
            $scheduledEnd = $date->copy()->setTime(20, 0);
        }

        // Реальное время начала (может быть небольшое отклонение)
        $actualStart = $scheduledStart->copy()->addMinutes(fake()->numberBetween(-5, 15));
        $actualEnd = $scheduledEnd->copy()->addMinutes(fake()->numberBetween(-10, 30));

        return Shift::create([
            'user_id' => $employee->id,
            'dealership_id' => $dealership->id,
            'shift_start' => $actualStart,
            'shift_end' => $actualEnd,
            'opening_photo_path' => 'shifts/demo/opening_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'closing_photo_path' => 'shifts/demo/closing_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'status' => 'closed',
            'shift_type' => $isMorningShift ? 'shift_1' : 'shift_2',
            'late_minutes' => 0,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
        ]);
    }

    /**
     * Создать открытую смену на сегодня.
     */
    private function createOpenShift(User $employee, AutoDealership $dealership, Carbon $date): Shift
    {
        $scheduledStart = $date->copy()->setTime(9, 0);
        $scheduledEnd = $date->copy()->setTime(18, 0);

        // Смена началась несколько часов назад
        $hoursAgo = fake()->numberBetween(1, 4);
        $actualStart = Carbon::now('Asia/Yekaterinburg')->subHours($hoursAgo);

        return Shift::create([
            'user_id' => $employee->id,
            'dealership_id' => $dealership->id,
            'shift_start' => $actualStart,
            'shift_end' => null,
            'opening_photo_path' => 'shifts/demo/opening_' . $employee->id . '_' . $date->format('Ymd') . '.jpg',
            'closing_photo_path' => null,
            'status' => 'open',
            'shift_type' => 'shift_1',
            'late_minutes' => fake()->boolean(20) ? fake()->numberBetween(5, 30) : 0,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
        ]);
    }

    /**
     * Создать замещения смен.
     */
    private function createReplacements(AutoDealership $dealership, $employees): int
    {
        if ($employees->count() < 2) {
            return 0;
        }

        $replacementsCount = 0;

        // Находим смены за последние 2 недели для замещений
        $shifts = Shift::where('dealership_id', $dealership->id)
            ->where('status', 'closed')
            ->where('shift_start', '>=', Carbon::now()->subDays(14))
            ->inRandomOrder()
            ->limit(3)
            ->get();

        foreach ($shifts as $shift) {
            // Выбираем другого сотрудника для замещения
            $replacingUser = $employees->where('id', '!=', $shift->user_id)->random();

            ShiftReplacement::create([
                'shift_id' => $shift->id,
                'replaced_user_id' => $shift->user_id,
                'replacing_user_id' => $replacingUser->id,
                'reason' => fake()->randomElement(self::REPLACEMENT_REASONS),
            ]);

            $replacementsCount++;
        }

        return $replacementsCount;
    }
}
