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
        Schema::create('payroll_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            $table->string('policy_code')->unique();
            $table->string('policy_name');
            $table->text('description')->nullable();
            
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            
            // Base Salary
            $table->decimal('daily_rate', 12, 2);
            $table->decimal('hourly_rate', 12, 2)->nullable();
            
            // Deductions
            $table->decimal('late_deduction_per_minute', 10, 4);
            $table->integer('late_minimum_minutes')->default(5);
            $table->decimal('absence_deduction_amount', 12, 2);
            $table->decimal('alpha_deduction_amount', 12, 2);
            
            // Overtime
            $table->decimal('overtime_rate_percent', 5, 2)->default(150);
            $table->decimal('overtime_rate_amount', 12, 2)->nullable();
            
            // Allowances & Bonus
            $table->decimal('daily_allowance', 12, 2)->default(0);
            $table->decimal('shift_allowance_amount', 12, 2)->default(0);
            $table->decimal('perfect_attendance_bonus', 12, 2)->default(0);
            
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            
            $table->timestamps();
            
            $table->index(['project_id', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_policies');
    }
};
