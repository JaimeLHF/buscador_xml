<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_states', function (Blueprint $table) {
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('consecutive_656');
        });
    }

    public function down(): void
    {
        Schema::table('sync_states', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });
    }
};
