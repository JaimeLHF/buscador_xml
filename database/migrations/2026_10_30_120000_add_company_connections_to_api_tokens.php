<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Conexões criadas no painel admin: cada chave presa a uma empresa, com contador e registro de cada chamada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            // Apagar a empresa apaga a chave: nula seria "organização inteira", um acesso maior que o combinado.
            $table->foreignId('company_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('total_chamadas')->default(0)->after('last_used_at');
            $table->string('ultimo_ip', 45)->nullable()->after('total_chamadas');
        });

        Schema::create('api_token_acessos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('rota', 40);
            $table->string('chave', 44)->nullable();
            $table->unsignedInteger('quantidade')->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_token_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_acessos');

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['total_chamadas', 'ultimo_ip']);
        });
    }
};
