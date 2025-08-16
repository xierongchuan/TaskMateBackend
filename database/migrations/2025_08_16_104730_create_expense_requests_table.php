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
        Schema::create('expense_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('requester_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status')->default('pending_manager'); // временно как string
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('accountant_id')->nullable();
            $table->text('manager_comment')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('requester_id')->references('id')->on('users');
            $table->foreign('manager_id')->references('id')->on('users');
            $table->foreign('accountant_id')->references('id')->on('users');
        });

        // затем меняем тип колонки на Postgres enum и устанавливаем default
        DB::statement(
            "ALTER TABLE expense_requests ALTER COLUMN status DROP DEFAULT;"
        );
        DB::statement(
            "ALTER TABLE expense_requests ALTER COLUMN status TYPE expense_status USING status::expense_status;"
        );
        DB::statement(
            "ALTER TABLE expense_requests ALTER COLUMN status SET DEFAULT 'pending_manager';"
        );

        // индексы
        DB::statement(
            "CREATE INDEX idx_expense_requests_requester ON expense_requests(requester_id);"
        );
        DB::statement(
            "CREATE INDEX idx_expense_requests_status_created ON expense_requests(status, created_at);"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropForeign(['requester_id']);
            $table->dropForeign(['manager_id']);
            $table->dropForeign(['accountant_id']);
        });

        Schema::dropIfExists('expense_requests');
    }
};
