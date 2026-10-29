<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// XML de nota antiga vindo do Meu Danfe: dados completos, mas remontado e sem a assinatura digital do emitente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->boolean('xml_reconstruido')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->dropColumn('xml_reconstruido');
        });
    }
};
