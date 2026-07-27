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
        Schema::create('attendance_progress_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('post_id')->nullable(); // Null untuk danru
            $table->integer('total_patrol_points')->default(0);
            $table->integer('scanned_patrol_points')->default(0);
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->dateTime('snapshot_at'); // Waktu ketika progress di-reset
            $table->json('scan_details'); // Detailed scan info untuk PDF generation
            $table->enum('snapshot_type', ['session_start', 'session_end', 'manual_reset'])->default('session_start');
            $table->timestamps();

            $table->foreign('attendance_id')->references('id')->on('attendances')->onDelete('cascade');
            $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('set null');
            
            $table->index(['attendance_id', 'assignment_id']);
            $table->index(['project_id', 'post_id']);
            $table->index('snapshot_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_progress_snapshots');
    }
};
