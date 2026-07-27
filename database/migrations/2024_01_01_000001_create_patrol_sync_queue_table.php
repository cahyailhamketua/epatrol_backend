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
        Schema::create('patrol_sync_queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('qr_code_id')->nullable();
            $table->string('qr_code')->nullable(); // Store QR code string untuk reference
            $table->float('scan_latitude');
            $table->float('scan_longitude');
            $table->float('scan_altitude')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('scan_time_device', 0); // Waktu scan dari device (local timezone)
            $table->dateTime('scan_time_utc', 0); // Waktu scan di UTC untuk konsistensi
            $table->json('photo_data'); // Base64 encoded photos atau paths
            $table->enum('status', ['pending', 'synced', 'failed', 'processing'])->default('pending');
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->dateTime('last_retry_at')->nullable();
            $table->unsignedBigInteger('patrol_scan_id')->nullable(); // Reference ke PatrolScan setelah sync
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('attendance_id')->references('id')->on('attendances')->onDelete('cascade');
            $table->foreign('qr_code_id')->references('id')->on('qr_codes')->onDelete('set null');
            $table->index('status');
            $table->index(['user_id', 'attendance_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patrol_sync_queues');
    }
};
