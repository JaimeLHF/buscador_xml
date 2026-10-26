<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CT-e passa a ser opcional por empresa: o foco é a NF-e e o CT-e chega em volume muito maior (frete),
 * quase sempre sem interesse para o usuário. Desligado por padrão, inclusive nas empresas já cadastradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('buscar_cte')->default(false)->after('manifestacao_automatica');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('buscar_cte');
        });
    }
};
