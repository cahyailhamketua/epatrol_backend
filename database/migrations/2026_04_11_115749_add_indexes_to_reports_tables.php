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
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['project_id', 'date']);
            $table->index('user_id');
            $table->index('assignment_id');
            $table->index('computed_status');
            $table->index('post_id');
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            $table->index('attendance_id');
            $table->index('scan_time');
            $table->index('qr_code_id');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['project_id', 'date']);
            $table->index('user_id');
            $table->index('team_id');
            $table->index('assignment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'date']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['assignment_id']);
            $table->dropIndex(['computed_status']);
            $table->dropIndex(['post_id']);
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            $table->dropIndex(['attendance_id']);
            $table->dropIndex(['scan_time']);
            $table->dropIndex(['qr_code_id']);
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'date']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['team_id']);
            $table->dropIndex(['assignment_id']);
        });
    }
};
