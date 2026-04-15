<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop suboptimal index if exists
            try {
                $table->dropIndex('idx_att_user_active');
            } catch (\Exception $e) {
                // Already dropped or doesn't exist
            }

            // High performance index for active attendance lookup
            // Sequence: user_id -> check_out_at (NULL check) -> check_in_at
            $table->index(['user_id', 'check_out_at', 'check_in_at'], 'idx_att_user_active_final');
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            // UNIQUE constraint to prevent duplicate scans at DB level
            // We use try-catch to ensure migration can be re-run if partially failed
            try {
                $table->unique(['attendance_id', 'qr_code_id'], 'uidx_att_qr');
            } catch (\Exception $e) {}
            
            try {
                $table->index(['attendance_id', 'created_at'], 'idx_att_scans_history');
            } catch (\Exception $e) {}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_att_user_active_final');
        });

        Schema::table('patrol_scans', function (Blueprint $table) {
            $table->dropUnique('uidx_att_qr');
            $table->dropIndex('idx_att_scans_history');
        });
    }
};
