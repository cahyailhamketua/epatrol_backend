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
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_policy_id')->constrained()->cascadeOnDelete();
            
            $table->integer('year');
            $table->integer('month');
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            
            $table->enum('status', ['DRAFT', 'FINALIZED', 'PAID', 'CANCELLED'])->default('DRAFT');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            
            // Summary cache untuk performance
            $table->integer('total_employees')->nullable();
            $table->decimal('total_payroll_amount', 14, 2)->nullable();
            $table->decimal('total_deductions', 14, 2)->nullable();
            $table->decimal('total_additions', 14, 2)->nullable();
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Unique: Hanya 1 payroll run per periode per project
            $table->unique(['project_id', 'year', 'month'], 'unique_payroll_period');
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
