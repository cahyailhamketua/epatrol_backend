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
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('report_date');
            $table->string('bos_name')->nullable();
            $table->string('bos_position');

            $table->string('shift')->nullable();

            $table->integer('total_personnel')->default(0);
            $table->integer('present_personnel')->default(0);
            $table->json('absent_personnel')->nullable();

            $table->longText('general_information')->nullable();
            $table->longText('further_escalation')->nullable();

            $table->json('incidents')->nullable();

            $table->json('berita_acara')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamps();

        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
