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
        // dealership_id column already exists in create_users_table migration
        // This migration was created later but the column was added to the base migration
        // Skip this migration to avoid duplicate column errors
        if (Schema::hasColumn('users', 'dealership_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('dealership_id')->unsigned()->nullable()->after('company_id');
            $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('set null');
            $table->index('dealership_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['dealership_id']);
            $table->dropIndex(['dealership_id']);
            $table->dropColumn('dealership_id');
        });
    }
};
