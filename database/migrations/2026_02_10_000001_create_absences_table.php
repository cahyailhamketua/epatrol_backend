<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Absence hanya untuk admin lapangan / HO dari sheet schedule.
     * Satu baris per schedule (1 sel = 1 user + 1 tanggal).
     * Tipe: C=cuti, S=sakit, I=izin, A=alfa — tanpa workflow approval.
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('schedule_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('absence_type', ['C', 'S', 'I', 'A']);

            $table->timestamps();

            $table->unique('schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
