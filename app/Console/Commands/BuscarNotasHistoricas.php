<?php

namespace App\Console\Commands;

use App\Jobs\BuscarChavesHistoricas;
use App\Models\ChaveHistorica;
use App\Services\MeuDanfe\MeuDanfeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('historico:buscar')]
#[Description('Retoma a busca no Meu Danfe das chaves do SPED que estão na fila (marcadas na tela de chaves do SPED)')]
class BuscarNotasHistoricas extends Command
{
    public function handle(): int
    {
        if (! MeuDanfeClient::configurado() || ! ChaveHistorica::where('status', 'pendente')->exists()) {
            return self::SUCCESS;
        }

        // Pelo job, que é único: nunca roda junto com uma busca disparada pela tela.
        BuscarChavesHistoricas::dispatch();
        $this->info('Busca no Meu Danfe na fila.');

        return self::SUCCESS;
    }
}
