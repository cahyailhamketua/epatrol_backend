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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
        
            $table->date('date');
        
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
        
            $table->decimal('checkin_lat', 10, 7)->nullable();
            $table->decimal('checkin_lng', 10, 7)->nullable();
            $table->decimal('checkout_lat', 10, 7)->nullable();
            $table->decimal('checkout_lng', 10, 7)->nullable();
        
            $table->string('attendance_status')->nullable();
        
            $table->integer('late_minutes')->nullable();
        
            $table->timestamps();
        });
        
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
