<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('schedules', 'membership_status')) {
                $table->enum('membership_status', ['FULL_EXISTING', 'PRORATE_IN', 'PRORATE_OUT'])
                    ->default('FULL_EXISTING')
                    ->after('team_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (Schema::hasColumn('schedules', 'membership_status')) {
                $table->dropColumn('membership_status');
            }
        });
    }
};

