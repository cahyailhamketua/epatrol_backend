<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_ter_brackets', function (Blueprint $table) {
            $table->id();
            $table->string('category', 1);
            $table->string('ptkp_group', 100)->nullable();
            $table->decimal('min_income', 14, 2);
            $table->decimal('max_income', 14, 2)->nullable();
            $table->decimal('rate', 8, 6);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'min_income'], 'payroll_ter_cat_income_idx');
        });

        $rows = [
            // Category A (TK/0 - TK/1 - K/0)
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 0, 'max_income' => 5400000, 'rate' => 0.0000],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 5400001, 'max_income' => 5650000, 'rate' => 0.0025],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 5650001, 'max_income' => 5950000, 'rate' => 0.0050],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 5950001, 'max_income' => 6300000, 'rate' => 0.0075],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 6300001, 'max_income' => 6750000, 'rate' => 0.0100],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 6750001, 'max_income' => 7500000, 'rate' => 0.0125],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 7500001, 'max_income' => 8550000, 'rate' => 0.0150],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 8550001, 'max_income' => 9650000, 'rate' => 0.0175],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 9650001, 'max_income' => 10050000, 'rate' => 0.0200],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 10050001, 'max_income' => 10350000, 'rate' => 0.0225],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 10350001, 'max_income' => 10700000, 'rate' => 0.0250],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 10700001, 'max_income' => 11050000, 'rate' => 0.0300],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 11050001, 'max_income' => 11600000, 'rate' => 0.0350],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 11600001, 'max_income' => 12500000, 'rate' => 0.0400],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 12500001, 'max_income' => 13750000, 'rate' => 0.0500],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 13750001, 'max_income' => 15100000, 'rate' => 0.0600],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 15100001, 'max_income' => 16950000, 'rate' => 0.0700],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 16950001, 'max_income' => 19750000, 'rate' => 0.0800],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 19750001, 'max_income' => 24150000, 'rate' => 0.0900],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 24150001, 'max_income' => 26450000, 'rate' => 0.1000],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 26450001, 'max_income' => 28000000, 'rate' => 0.1100],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 28000001, 'max_income' => 30050000, 'rate' => 0.1200],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 30050001, 'max_income' => 32400000, 'rate' => 0.1300],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 32400001, 'max_income' => 35400000, 'rate' => 0.1400],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 35400001, 'max_income' => 39100000, 'rate' => 0.1500],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 39100001, 'max_income' => 43850000, 'rate' => 0.1600],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 43850001, 'max_income' => 47800000, 'rate' => 0.1700],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 47800001, 'max_income' => 51400000, 'rate' => 0.1800],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 51400001, 'max_income' => 56300000, 'rate' => 0.1900],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 56300001, 'max_income' => 62200000, 'rate' => 0.2000],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 62200001, 'max_income' => 68600000, 'rate' => 0.2100],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 68600001, 'max_income' => 77500000, 'rate' => 0.2200],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 77500001, 'max_income' => 89000000, 'rate' => 0.2300],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 89000001, 'max_income' => 103000000, 'rate' => 0.2400],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 103000001, 'max_income' => 125000000, 'rate' => 0.2500],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 125000001, 'max_income' => 157000000, 'rate' => 0.2600],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 157000001, 'max_income' => 206000000, 'rate' => 0.2700],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 206000001, 'max_income' => 337000000, 'rate' => 0.2800],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 337000001, 'max_income' => 454000000, 'rate' => 0.2900],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 454000001, 'max_income' => 550000000, 'rate' => 0.3000],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 550000001, 'max_income' => 695000000, 'rate' => 0.3100],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 695000001, 'max_income' => 910000000, 'rate' => 0.3200],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 910000001, 'max_income' => 1400000000, 'rate' => 0.3300],
            ['category' => 'A', 'ptkp_group' => 'TK/0 - TK/1 - K/0', 'min_income' => 1400000001, 'max_income' => null, 'rate' => 0.3400],

            // Category B (TK/2 - TK/3 - K/1 - K/2)
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 0, 'max_income' => 6200000, 'rate' => 0.0000],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 6200001, 'max_income' => 6500000, 'rate' => 0.0025],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 6500001, 'max_income' => 6850000, 'rate' => 0.0050],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 6850001, 'max_income' => 7300000, 'rate' => 0.0075],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 7300001, 'max_income' => 9200000, 'rate' => 0.0100],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 9200001, 'max_income' => 10750000, 'rate' => 0.0125],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 10750001, 'max_income' => 11250000, 'rate' => 0.0150],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 11250001, 'max_income' => 11600000, 'rate' => 0.0175],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 11600001, 'max_income' => 12600000, 'rate' => 0.0200],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 12600001, 'max_income' => 13600000, 'rate' => 0.0225],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 13600001, 'max_income' => 14950000, 'rate' => 0.0250],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 14950001, 'max_income' => 16400000, 'rate' => 0.0300],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 16400001, 'max_income' => 18450000, 'rate' => 0.0350],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 18450001, 'max_income' => 21850000, 'rate' => 0.0400],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 21850001, 'max_income' => 26000000, 'rate' => 0.0500],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 26000001, 'max_income' => 27700000, 'rate' => 0.0600],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 27700001, 'max_income' => 29350000, 'rate' => 0.0700],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 29350001, 'max_income' => 31450000, 'rate' => 0.0800],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 31450001, 'max_income' => 33950000, 'rate' => 0.0900],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 33950001, 'max_income' => 37100000, 'rate' => 0.1000],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 37100001, 'max_income' => 41100000, 'rate' => 0.1100],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 41100001, 'max_income' => 45800000, 'rate' => 0.1200],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 45800001, 'max_income' => 49500000, 'rate' => 0.1300],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 49500001, 'max_income' => 53800000, 'rate' => 0.1400],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 53800001, 'max_income' => 58500000, 'rate' => 0.1500],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 58500001, 'max_income' => 64000000, 'rate' => 0.1600],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 64000001, 'max_income' => 71000000, 'rate' => 0.1700],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 71000001, 'max_income' => 80000000, 'rate' => 0.1800],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 80000001, 'max_income' => 93000000, 'rate' => 0.1900],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 93000001, 'max_income' => 109000000, 'rate' => 0.2000],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 109000001, 'max_income' => 129000000, 'rate' => 0.2100],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 129000001, 'max_income' => 163000000, 'rate' => 0.2200],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 163000001, 'max_income' => 211000000, 'rate' => 0.2300],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 211000001, 'max_income' => 374000000, 'rate' => 0.2400],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 374000001, 'max_income' => 459000000, 'rate' => 0.2500],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 459000001, 'max_income' => 555000000, 'rate' => 0.2600],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 555000001, 'max_income' => 704000000, 'rate' => 0.2700],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 704000001, 'max_income' => 957000000, 'rate' => 0.2800],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 957000001, 'max_income' => 1405000000, 'rate' => 0.2900],
            ['category' => 'B', 'ptkp_group' => 'TK/2 - TK/3 - K/1 - K/2', 'min_income' => 1405000001, 'max_income' => null, 'rate' => 0.3000],

            // Category C (K/3)
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 0, 'max_income' => 6600000, 'rate' => 0.0000],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 6600001, 'max_income' => 6950000, 'rate' => 0.0025],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 6950001, 'max_income' => 7350000, 'rate' => 0.0050],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 7350001, 'max_income' => 7800000, 'rate' => 0.0075],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 7800001, 'max_income' => 8850000, 'rate' => 0.0100],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 8850001, 'max_income' => 9800000, 'rate' => 0.0125],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 9800001, 'max_income' => 10950000, 'rate' => 0.0150],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 10950001, 'max_income' => 11200000, 'rate' => 0.0175],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 11200001, 'max_income' => 12050000, 'rate' => 0.0200],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 12050001, 'max_income' => 12950000, 'rate' => 0.0300],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 12950001, 'max_income' => 14150000, 'rate' => 0.0400],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 14150001, 'max_income' => 15550000, 'rate' => 0.0500],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 15550001, 'max_income' => 17050000, 'rate' => 0.0600],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 17050001, 'max_income' => 19500000, 'rate' => 0.0700],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 19500001, 'max_income' => 22700000, 'rate' => 0.0800],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 22700001, 'max_income' => 26600000, 'rate' => 0.0900],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 26600001, 'max_income' => 28100000, 'rate' => 0.1000],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 28100001, 'max_income' => 30100000, 'rate' => 0.1100],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 30100001, 'max_income' => 32600000, 'rate' => 0.1200],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 32600001, 'max_income' => 35400000, 'rate' => 0.1300],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 35400001, 'max_income' => 38900000, 'rate' => 0.1400],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 38900001, 'max_income' => 43000000, 'rate' => 0.1500],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 43000001, 'max_income' => 47400000, 'rate' => 0.1600],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 47400001, 'max_income' => 51200000, 'rate' => 0.1700],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 51200001, 'max_income' => 55800000, 'rate' => 0.1800],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 55800001, 'max_income' => 60400000, 'rate' => 0.1900],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 60400001, 'max_income' => 66700000, 'rate' => 0.2000],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 66700001, 'max_income' => 74500000, 'rate' => 0.2100],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 74500001, 'max_income' => 83200000, 'rate' => 0.2200],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 83200001, 'max_income' => 95600000, 'rate' => 0.2300],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 95600001, 'max_income' => 110000000, 'rate' => 0.2400],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 110000001, 'max_income' => 134000000, 'rate' => 0.2500],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 134000001, 'max_income' => 169000000, 'rate' => 0.2600],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 169000001, 'max_income' => 221000000, 'rate' => 0.2700],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 221000001, 'max_income' => 390000000, 'rate' => 0.2800],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 390000001, 'max_income' => 463000000, 'rate' => 0.2900],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 463000001, 'max_income' => 561000000, 'rate' => 0.3000],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 561000001, 'max_income' => 709000000, 'rate' => 0.3100],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 709000001, 'max_income' => 965000000, 'rate' => 0.3200],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 965000001, 'max_income' => 1419000000, 'rate' => 0.3300],
            ['category' => 'C', 'ptkp_group' => 'K/3', 'min_income' => 1419000001, 'max_income' => null, 'rate' => 0.3400],
        ];

        $now = now();
        foreach ($rows as $i => &$row) {
            $row['sort_order'] = $i + 1;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('payroll_ter_brackets')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_ter_brackets');
    }
};
