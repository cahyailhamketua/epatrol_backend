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
        Schema::create('overtime_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Asal jadwal OFF (schedule assignment)
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();

            // Bukti dari attendance (created saat check-in)
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();

            // Assignment OFF (scheduled) dan assignment kerja lembur (P/M)
            $table->foreignId('scheduled_assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('work_assignment_id')->constrained('assignments')->cascadeOnDelete();

            $table->date('date');
            $table->string('display_code', 16); // contoh: O/P, O/M
            // Tidak diperlukan untuk business logic sekarang (OT dihitung per shift).
            $table->unsignedInteger('minutes')->default(0);

            $table->timestamps();

            $table->unique('schedule_id');
            $table->unique('attendance_id');
            $table->index(['project_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_logs');
    }
};
