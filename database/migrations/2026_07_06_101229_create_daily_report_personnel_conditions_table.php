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
        Schema::create('daily_report_personnel_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_report_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('position')->nullable();

            $table->enum('physical_condition', [
                'baik',
                'perlu_perhatian',
                'tidak_fit'
            ]);

            $table->text('remarks')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_report_personnel_conditions');
    }
};
