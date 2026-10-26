<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de convite compartilhável: quem abre o link cria a conta (ou entra) e vira membro, sem depender de e-mail.
 * O hash serve para a busca; o token cifrado permite ao administrador copiar o link de novo depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('join_token')->nullable()->after('name');
            $table->string('join_token_hash', 64)->nullable()->unique()->after('join_token');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['join_token_hash']);
            $table->dropColumn(['join_token', 'join_token_hash']);
        });
    }
};
