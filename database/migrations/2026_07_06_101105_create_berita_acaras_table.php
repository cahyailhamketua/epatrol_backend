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
        Schema::create('berita_acaras', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            // BA/003/VII/2026/SECURITY
            $table->string('document_number')->unique();

            // 3
            $table->unsignedInteger('sequence_number')->nullable();

            $table->date('incident_date');

            $table->time('incident_time');

            $table->string('subject')->nullable();

            $table->string('location')->nullable();

            $table->longText('description')->nullable();

            // daftar kronologi
            $table->json('chronologies')->nullable();

            // daftar tindakan
            $table->json('actions_taken')->nullable();

            $table->string('inspector_name')->nullable();

            $table->string('inspector_position')->nullable();

            $table->string('acknowledged_by')->nullable();

            $table->string('acknowledged_position')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->index([
                'project_id',
                'incident_date'
            ]);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('berita_acaras');
    }
};
