<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\Task;
use App\Models\User;
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

            // 5. Create Tasks
            // 5.1 Individual Tasks for each employee
            foreach ($employees as $emp) {
                Task::factory(2)->create([
                    'dealership_id' => $dealership->id,
                    'creator_id' => $manager->id,
                    'task_type' => 'individual',
                    'title' => 'Personal Task for ' . $emp->full_name,
                ]);
            }

            // 5.2 Group Task
            Task::factory(2)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'group',
                'title' => 'Group Meeting Task',
            ]);

            $this->command->info(" - Created Tasks");
        }

        $this->command->info('Demo Data Creation Completed!');
    }
}
