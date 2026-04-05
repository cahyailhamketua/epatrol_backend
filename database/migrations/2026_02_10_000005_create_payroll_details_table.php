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
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            
            // Working details
            $table->integer('working_days')->default(0);
            $table->integer('worked_hours')->default(0);
            $table->decimal('base_salary', 14, 2);
            
            // Attendance details
            $table->integer('attendance_count')->default(0);
            $table->integer('late_count')->default(0);
            $table->integer('late_total_minutes')->default(0);
            
            // Absence details
            $table->integer('absence_count')->default(0);
            $table->integer('absence_type_sakit')->default(0);
            $table->integer('absence_type_izin')->default(0);
            $table->integer('absence_type_cuti')->default(0);
            
            // Alpha details
            $table->integer('alpha_count')->default(0);
            
            // Overtime details
            $table->integer('overtime_count')->default(0);
            $table->integer('overtime_total_hours')->default(0);
            
            // Deductions
            $table->decimal('deduction_late', 14, 2)->default(0);
            $table->decimal('deduction_absence', 14, 2)->default(0);
            $table->decimal('deduction_cuti', 14, 2)->default(0);
            $table->decimal('deduction_alpha', 14, 2)->default(0);
            $table->decimal('deduction_other', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            
            // Additions
            $table->decimal('addition_overtime', 14, 2)->default(0);
            $table->decimal('addition_allowance', 14, 2)->default(0);
            $table->decimal('addition_bonus', 14, 2)->default(0);
            $table->decimal('addition_other', 14, 2)->default(0);
            $table->decimal('total_additions', 14, 2)->default(0);
            
            // Final
            $table->decimal('net_salary', 14, 2);
            
            // Payment details
            $table->string('payment_method')->nullable();
            $table->date('payment_date')->nullable();
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Unique: Hanya 1 payroll detail per user per run
            $table->unique(['payroll_run_id', 'user_id'], 'unique_payroll_user');
            $table->index(['payroll_run_id']);
            $table->index(['user_id']);
            $table->index(['project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};
