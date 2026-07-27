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
        Schema::create('daily_report_uniform_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('uniform_personnel_id')
                ->constrained('daily_report_uniform_personnels')
                ->cascadeOnDelete();

            $table->foreignId('uniform_component_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('status', [
                'ada',
                'tidak_ada',
            ]);

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_report_uniform_checks');
    }
};
