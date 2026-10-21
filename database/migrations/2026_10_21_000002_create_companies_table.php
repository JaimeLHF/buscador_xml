<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('cnpj', 14);
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->char('uf', 2);
            $table->unsignedTinyInteger('ambiente')->default(2);
            $table->boolean('ativo')->default(true);
            $table->boolean('manifestacao_automatica')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'cnpj', 'ambiente']);
        });

        // Estado da distribuição DF-e por empresa e tipo de documento: NF-e e CT-e têm NSU independentes.
        Schema::create('sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 3);
            $table->string('ult_nsu', 15)->default('000000000000000');
            $table->string('max_nsu', 15)->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_cstat', 3)->nullable();
            $table->string('last_xmotivo')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_656')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'tipo']);
            $table->index('next_check_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('companies');
    }
};
