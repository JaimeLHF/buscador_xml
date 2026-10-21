<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por documento (chave): o resumo (resNFe) é promovido a completo quando o procNFe chega.
        Schema::create('dfe_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 3);
            $table->string('chave', 44);
            $table->string('nsu', 15);
            $table->string('schema', 30);
            $table->string('emitente_cnpj', 14)->nullable();
            $table->string('emitente_nome')->nullable();
            $table->timestamp('data_emissao')->nullable();
            $table->decimal('valor_total', 15, 2)->nullable();
            $table->string('situacao', 20);
            $table->boolean('tem_xml_completo')->default(false);
            $table->string('manifestacao_status', 30)->nullable();
            $table->string('xml_path')->nullable();
            $table->string('resumo_xml_path')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'chave']);
            $table->index(['company_id', 'data_emissao']);
            $table->index(['company_id', 'emitente_cnpj']);
        });

        Schema::create('dfe_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chave', 44);
            $table->string('nsu', 15)->nullable();
            $table->string('schema', 30);
            $table->string('tp_evento', 6);
            $table->unsignedSmallInteger('n_seq_evento')->default(1);
            $table->string('descricao')->nullable();
            $table->string('autor_cnpj', 14)->nullable();
            $table->timestamp('data_evento')->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->string('cstat', 3)->nullable();
            $table->string('direcao', 10)->default('recebido');
            $table->string('xml_path')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'chave', 'tp_evento', 'n_seq_evento']);
            $table->index(['company_id', 'chave']);
        });

        // Log técnico das chamadas. Nunca armazenar senha, certificado ou XML aqui.
        Schema::create('sefaz_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('servico', 30);
            $table->unsignedTinyInteger('tp_amb');
            $table->string('cstat', 3)->nullable();
            $table->string('xmotivo')->nullable();
            $table->string('ult_nsu_enviado', 15)->nullable();
            $table->string('ult_nsu_retornado', 15)->nullable();
            $table->string('max_nsu', 15)->nullable();
            $table->string('chave', 44)->nullable();
            $table->unsignedSmallInteger('documentos')->default(0);
            $table->unsignedInteger('duracao_ms')->default(0);
            $table->string('erro')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sefaz_requests');
        Schema::dropIfExists('dfe_events');
        Schema::dropIfExists('dfe_documents');
    }
};
