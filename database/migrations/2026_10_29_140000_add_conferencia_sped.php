<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O XML do Meu Danfe vem sem assinatura: o que prova que ele é a nota declarada é bater valor e data com o C100 do SPED.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chaves_historicas', function (Blueprint $table) {
            $table->decimal('sped_valor', 15, 2)->nullable();
            $table->date('sped_data')->nullable();
        });

        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->string('conferencia_sped', 10)->nullable();
            $table->string('conferencia_sped_detalhe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chaves_historicas', function (Blueprint $table) {
            $table->dropColumn(['sped_valor', 'sped_data']);
        });

        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->dropColumn(['conferencia_sped', 'conferencia_sped_detalhe']);
        });
    }
};
