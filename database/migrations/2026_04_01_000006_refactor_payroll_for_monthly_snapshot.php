<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('period', 7)->nullable()->after('month');
            $table->timestamp('generated_at')->nullable()->after('paid_at');
            $table->timestamp('released_at')->nullable()->after('generated_at');

            $table->index(['project_id', 'period'], 'payroll_runs_project_period_idx');
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->string('period', 7)->nullable()->after('assignment_id');
            $table->string('user_nik', 100)->nullable()->after('period');
            $table->string('user_bank_name', 100)->nullable()->after('user_nik');
            $table->string('user_bank_account', 100)->nullable()->after('user_bank_name');
            $table->string('user_position', 100)->nullable()->after('user_bank_account');

            $table->integer('schedule_full_existing_count')->default(0)->after('working_days');
            $table->integer('schedule_prorate_in_count')->default(0)->after('schedule_full_existing_count');
            $table->integer('schedule_prorate_out_count')->default(0)->after('schedule_prorate_in_count');

            $table->json('earnings_breakdown')->nullable()->after('total_additions');
            $table->json('deductions_breakdown')->nullable()->after('earnings_breakdown');
            $table->json('other_breakdown')->nullable()->after('deductions_breakdown');
            $table->json('manual_breakdown')->nullable()->after('other_breakdown');
            $table->json('daily_breakdown')->nullable()->after('manual_breakdown');
            $table->json('calculation_meta')->nullable()->after('daily_breakdown');

            $table->index(['project_id', 'period'], 'payroll_details_project_period_idx');
        });

        Schema::create('payroll_user_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('component_key');
            $table->string('component_name');
            $table->enum('component_group', ['earning', 'deduction', 'other']);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('last_used_period', 7)->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'user_id', 'component_key', 'component_group'],
                'payroll_templates_unique_component'
            );
            $table->index(['project_id', 'user_id'], 'payroll_templates_project_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_user_templates');

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropIndex('payroll_details_project_period_idx');
            $table->dropColumn([
                'period',
                'user_nik',
                'user_bank_name',
                'user_bank_account',
                'user_position',
                'schedule_full_existing_count',
                'schedule_prorate_in_count',
                'schedule_prorate_out_count',
                'earnings_breakdown',
                'deductions_breakdown',
                'other_breakdown',
                'manual_breakdown',
                'daily_breakdown',
                'calculation_meta',
            ]);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropIndex('payroll_runs_project_period_idx');
            $table->dropColumn(['period', 'generated_at', 'released_at']);
        });
    }
};
