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
        Schema::create('team_users', function (Blueprint $table) {

            $table->id();
        
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();
        
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
        
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        
            $table->timestamps();
        
            $table->unique(['team_id','user_id','start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_users');
    }
};
