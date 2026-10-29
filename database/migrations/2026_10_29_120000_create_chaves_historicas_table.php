<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chaves de notas antigas (vindas do SPED) aguardando a busca do XML no Meu Danfe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chaves_historicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('chave', 44);
            $table->string('status', 20)->default('pendente')->index();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->string('ultimo_erro')->nullable();
            $table->timestamp('buscado_em')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chaves_historicas');
    }
};
