<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo modo de busca: em vez do certificado de cada empresa, um certificado do escritório contábil
 * consulta o web service da SEF-SC (NfeDownloadContab) para todas as empresas catarinenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('modo_busca', 30)->default('certificado_empresa');
        });

        // Certificado do escritório: pertence à organização, não a uma empresa.
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
            $table->foreignId('organization_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->index(['organization_id', 'active']);
        });

        Schema::create('sef_sc_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // NSU da SEF-SC (até 18 dígitos), independente do NSU do Ambiente Nacional.
            $table->string('ult_nsu', 18)->default('0');
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_cstat', 4)->nullable();
            $table->string('last_xmotivo')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
        });

        foreach (['dfe_documents', 'dfe_events'] as $tabela) {
            Schema::table($tabela, fn (Blueprint $table) => $table->string('nsu', 18)->nullable($tabela === 'dfe_events')->change());
        }

        Schema::table('sefaz_requests', function (Blueprint $table) {
            $table->string('ult_nsu_enviado', 18)->nullable()->change();
            $table->string('ult_nsu_retornado', 18)->nullable()->change();
            $table->string('max_nsu', 18)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sef_sc_sync_states');

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'active']);
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('modo_busca');
        });
    }
};
