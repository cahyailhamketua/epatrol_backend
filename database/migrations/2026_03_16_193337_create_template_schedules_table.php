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
        Schema::create('template_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            // Pola assignment per hari, disimpan sebagai array JSON kode shift: ["P","P","M","M","O","O"]
            $table->json('pattern');

            // Tanggal awal pattern mulai berlaku (dipakai untuk menghitung offset lintas bulan)
            $table->date('start_date');

            $table->timestamps();

            $table->unique('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_schedules');
    }
};
