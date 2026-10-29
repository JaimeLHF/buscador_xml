<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O SPED traz mais que a chave: fornecedor (0150), natureza da compra (CFOP do C190), situação e impostos (C100).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chaves_historicas', function (Blueprint $table) {
            $table->string('fornecedor_nome', 120)->nullable();
            $table->string('fornecedor_uf', 2)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->string('natureza', 20)->nullable()->index();
            $table->string('sped_situacao', 2)->nullable();
            $table->json('sped_impostos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chaves_historicas', function (Blueprint $table) {
            $table->dropColumn(['fornecedor_nome', 'fornecedor_uf', 'cfop', 'natureza', 'sped_situacao', 'sped_impostos']);
        });
    }
};
