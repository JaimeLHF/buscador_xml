<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A SEF-SC usa códigos de 4 dígitos (ex.: 8002 – requisitante não é contador da empresa).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_requests', function (Blueprint $table) {
            $table->string('cstat', 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_requests', function (Blueprint $table) {
            $table->string('cstat', 3)->nullable()->change();
        });
    }
};
