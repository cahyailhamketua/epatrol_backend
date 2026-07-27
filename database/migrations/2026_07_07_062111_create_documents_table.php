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
       Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('document_type_id')
                ->nullable() // Membuat kolom boleh kosong di database
                ->constrained()
                ->nullOnDelete(); // Jika tipe dokumen dihapus, kolom ini jadi NULL (dokumen tidak ikut terhapus)

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('document_date');

            $table->string('file_name');

            $table->string('file_path');

            $table->timestamps();

            $table->index([
                'project_id',
                'document_date'
            ]);

            $table->index([
                'document_type_id',
                'document_date'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
