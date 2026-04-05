<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // public function up(): void
    // {
    //     Schema::create('activities', function (Blueprint $table) {
    //         $table->id();
    //         $table->foreignId('post_id')->constrained()->cascadeOnDelete();
        
    //         $table->string('name');          // nama aktivitas
    //         $table->string('location');
        
    //         $table->boolean('active')->default(true);
        
    //         $table->timestamps();
        
    //         // mencegah duplikasi activity dalam 1 pos
    //         $table->unique(['post_id', 'name']);
    //     });
        
    // }
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
        
            $table->foreignId('project_id')
                  ->nullable()
                  ->constrained()
                  ->cascadeOnDelete();
        
            $table->foreignId('post_id')
                  ->nullable()
                  ->constrained()
                  ->cascadeOnDelete();
        
            $table->string('name');
            $table->string('location');
        
            $table->boolean('active')->default(true);
        
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
