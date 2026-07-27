<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE absences MODIFY absence_type ENUM('C','S','I','A','SOC-A') NOT NULL");
    }

    public function down(): void
    {
        // Data SOC-A diturunkan ke A agar rollback tidak gagal.
        DB::statement("UPDATE absences SET absence_type = 'A' WHERE absence_type = 'SOC-A'");
        DB::statement("ALTER TABLE absences MODIFY absence_type ENUM('C','S','I','A') NOT NULL");
    }
};
