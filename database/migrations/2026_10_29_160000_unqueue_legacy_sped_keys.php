<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Antes da tela de seleção, enviar o SPED já punha todas as chaves na fila da consulta paga. Agora só entra
// na fila o que alguém marca: as chaves antigas que nunca foram consultadas voltam a aguardar seleção.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chaves_historicas')
            ->where('status', 'pendente')
            ->whereNull('buscado_em')
            ->where('tentativas', 0)
            ->update(['status' => 'importada', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sem volta: não dá para saber quais "aguardando seleção" vieram da fila antiga.
    }
};
