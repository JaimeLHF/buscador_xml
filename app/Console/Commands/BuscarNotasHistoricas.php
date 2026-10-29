<?php

namespace App\Console\Commands;

use App\Models\ChaveHistorica;
use App\Services\MeuDanfe\BuscaHistoricaService;
use App\Services\MeuDanfe\MeuDanfeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('historico:buscar {--limite=300}')]
#[Description('Busca no Meu Danfe o XML das notas antigas importadas do SPED')]
class BuscarNotasHistoricas extends Command
{
    public function handle(BuscaHistoricaService $service): int
    {
        if (! MeuDanfeClient::configurado() || ! ChaveHistorica::where('status', 'pendente')->exists()) {
            return self::SUCCESS;
        }

        $r = $service->buscar((int) $this->option('limite'));

        $this->info("Encontradas: {$r['encontradas']} · Não encontradas: {$r['nao_encontradas']} · Erros: {$r['erros']}");

        if ($r['sem_saldo']) {
            $this->warn('Sem saldo no Meu Danfe: as chaves restantes continuam na fila.');
        }

        return self::SUCCESS;
    }
}
