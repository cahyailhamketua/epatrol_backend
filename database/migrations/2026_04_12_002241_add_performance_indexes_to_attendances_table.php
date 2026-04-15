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
            // Index untuk mempercepat resolve active attendance per user
            $table->index(['user_id', 'date'], 'idx_att_user_date');
            
            // Index untuk filter dashboard/report per project
            $table->index(['project_id', 'date'], 'idx_att_project_date');
            
            // Index untuk resolve unclosed attendance (long running query)
            $table->index(['user_id', 'check_in_at', 'check_out_at'], 'idx_att_user_active');
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            // Index untuk sortir dan filter scan_time
            $table->index('scan_time', 'idx_scan_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_att_user_date');
            $table->dropIndex('idx_att_project_date');
            $table->dropIndex('idx_att_user_active');
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            $table->dropIndex('idx_scan_time');
        });
    }
};
