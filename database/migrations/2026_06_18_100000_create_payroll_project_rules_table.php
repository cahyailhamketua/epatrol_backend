<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_project_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('backup_rate', 14, 2)->default(0);
            $table->decimal('potongan_sakit', 14, 2)->default(0);
            $table->decimal('potongan_izin', 14, 2)->default(0);
            $table->decimal('potongan_cuti', 14, 2)->default(0);
            $table->decimal('potongan_alpha', 14, 2)->default(0);
            $table->decimal('potongan_soc_a', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_project_rules');
    }
};
