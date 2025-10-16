<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip ENUM creation for SQLite (testing)
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::statement("
            CREATE TYPE user_roles AS ENUM (
              'owner',
              'manager',
              'observer',
              'employee'
            );
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::statement("DROP TYPE IF EXISTS user_roles;");
    }
};
