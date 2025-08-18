<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id'); // BIGSERIAL
            $table->string('login', 100)->unique();
            $table->string('full_name', 2557);
            $table->bigInteger('telegram_id');
            $table->string('phone', 50);
            $table->string('role', 50)->default('user');
            $table->bigInteger('company_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            "ALTER TABLE users ALTER COLUMN role DROP DEFAULT;"
        );
        DB::statement(
            "ALTER TABLE users ALTER COLUMN role TYPE roles_enum USING role::roles_enum;"
        );
        DB::statement(
            "ALTER TABLE users ALTER COLUMN role SET DEFAULT 'user';"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
