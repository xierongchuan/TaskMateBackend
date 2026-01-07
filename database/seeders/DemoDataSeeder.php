<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Demo Data...');

        // 1. Create Dealerships
        $dealerships = [
            [
                'name' => 'Avto Salon Center',
                'address' => 'Tashkent, Amir Temur 1',
            ],
            [
                'name' => 'Avto Salon Sever',
                'address' => 'Tashkent, Yunusabad 19',
            ],
            [
                'name' => 'Auto Salon Lux',
                'address' => 'Tashkent, Chilanzar 5',
            ],
        ];

        foreach ($dealerships as $index => $data) {
            $dealership = AutoDealership::factory()->create($data);
            $this->command->info("Created dealership: {$dealership->name}");

            // 2. Create Manager
            $managerLogin = 'manager' . ($index + 1);
            $manager = User::updateOrCreate(
                ['login' => $managerLogin],
                [
                    'full_name' => "Manager of {$dealership->name}",
                    'password' => Hash::make('password'),
                    'role' => Role::MANAGER,
                    'dealership_id' => $dealership->id,
                    'phone' => fake()->phoneNumber(),
                    'telegram_id' => fake()->unique()->randomNumber(9),
                ]
            );
            $this->command->info(" - Created Manager: {$manager->login} / password");

            // 3. Create Employees
            $employees = [];
            for ($i = 1; $i <= 3; $i++) {
                $empLogin = 'emp' . ($index + 1) . '_' . $i;
                $employee = User::updateOrCreate(
                    ['login' => $empLogin],
                    [
                        'full_name' => "Employee {$i} of {$dealership->name}",
                        'password' => Hash::make('password'),
                        'role' => Role::EMPLOYEE,
                        'dealership_id' => $dealership->id,
                        'phone' => fake()->phoneNumber(),
                        'telegram_id' => fake()->unique()->randomNumber(9),
                    ]
                );
                $employees[] = $employee;
                $this->command->info(" - Created Employee: {$employee->login} / password");
            }

            // 4. Create Important Links
            ImportantLink::factory(5)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
            ]);
            $this->command->info(" - Created 5 Important Links");

            // 5. Create Task Generators (replacing recurring tasks)
            $this->createTaskGenerators($dealership, $manager, $employees);

            // 6. Create One-time Tasks
            $this->createOneTimeTasks($dealership, $manager, $employees);
        }

        $this->command->info('Demo Data Creation Completed!');
    }

    /**
     * Create task generators for a dealership.
     */
    private function createTaskGenerators($dealership, $manager, array $employees): void
    {
        $generators = [
            [
                'title' => 'Ежедневная проверка автомобилей',
                'description' => 'Проверить состояние всех автомобилей на площадке',
                'recurrence' => 'daily',
                'recurrence_time' => '09:00',
                'deadline_time' => '12:00',
                'task_type' => 'group',
                'response_type' => 'complete',
                'priority' => 'high',
                'tags' => ['проверка', 'автомобили'],
            ],
            [
                'title' => 'Еженедельный отчет по продажам',
                'description' => 'Подготовить отчет по продажам за неделю',
                'recurrence' => 'weekly',
                'recurrence_time' => '10:00',
                'deadline_time' => '18:00',
                'recurrence_day_of_week' => 5, // Friday
                'task_type' => 'individual',
                'response_type' => 'complete',
                'priority' => 'medium',
                'tags' => ['отчет', 'продажи'],
            ],
            [
                'title' => 'Ежемесячная инвентаризация',
                'description' => 'Провести инвентаризацию склада запчастей',
                'recurrence' => 'monthly',
                'recurrence_time' => '09:00',
                'deadline_time' => '18:00',
                'recurrence_day_of_month' => -1, // Last day of month
                'task_type' => 'group',
                'response_type' => 'complete',
                'priority' => 'high',
                'tags' => ['инвентаризация', 'склад'],
            ],
        ];

        foreach ($generators as $genData) {
            $generator = TaskGenerator::create([
                'title' => $genData['title'],
                'description' => $genData['description'],
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'recurrence' => $genData['recurrence'],
                'recurrence_time' => $genData['recurrence_time'],
                'deadline_time' => $genData['deadline_time'],
                'recurrence_day_of_week' => $genData['recurrence_day_of_week'] ?? null,
                'recurrence_day_of_month' => $genData['recurrence_day_of_month'] ?? null,
                'start_date' => Carbon::today(),
                'task_type' => $genData['task_type'],
                'response_type' => $genData['response_type'],
                'priority' => $genData['priority'],
                'tags' => $genData['tags'],
                'is_active' => true,
            ]);

            // Assign employees
            if ($genData['task_type'] === 'group') {
                foreach ($employees as $emp) {
                    TaskGeneratorAssignment::create([
                        'generator_id' => $generator->id,
                        'user_id' => $emp->id,
                    ]);
                }
            } else {
                // Individual - assign to first employee
                TaskGeneratorAssignment::create([
                    'generator_id' => $generator->id,
                    'user_id' => $employees[0]->id,
                ]);
            }
        }

        $this->command->info(" - Created " . count($generators) . " Task Generators");
    }

    /**
     * Create one-time tasks for a dealership.
     */
    private function createOneTimeTasks($dealership, $manager, array $employees): void
    {
        // Individual Tasks for each employee
        foreach ($employees as $emp) {
            $tasks = Task::factory(2)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'individual',
                'title' => 'Personal Task for ' . $emp->full_name,
                'recurrence' => 'none',
            ]);

            foreach ($tasks as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => now(),
                ]);
            }
        }

        // Group Tasks
        $groupTasks = Task::factory(2)->create([
            'dealership_id' => $dealership->id,
            'creator_id' => $manager->id,
            'task_type' => 'group',
            'title' => 'Group Meeting Task',
            'recurrence' => 'none',
        ]);

        foreach ($groupTasks as $task) {
            foreach ($employees as $emp) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => now(),
                ]);
            }
        }

        $taskCount = count($employees) * 2 + 2;
        $this->command->info(" - Created {$taskCount} One-time Tasks");
    }
}

