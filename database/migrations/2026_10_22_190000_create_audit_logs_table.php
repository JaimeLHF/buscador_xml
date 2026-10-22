<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Sem usuário: ação automática do sistema (ex.: Ciência enviada pela fila).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dfe_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acao', 40);
            $table->string('descricao')->nullable();
            $table->jsonb('dados')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['dfe_document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
