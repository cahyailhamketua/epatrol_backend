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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('location_latitude')->nullable();
            $table->string('location_longitude')->nullable();
            $table->string('location_address')->nullable();
            $table->string('location_city')->nullable();
            $table->integer('radius')->default(100)->comment('Geofence radius in meters (default: 100m)');
            $table->string('timezone')->default('Asia/Jakarta')->comment('Timezone: Asia/Jakarta (WIB), Asia/Makassar (WITA), Asia/Jayapura (WIT)');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
