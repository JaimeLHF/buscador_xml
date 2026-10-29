<?php

namespace App\Jobs;

use App\Enums\ChaveHistoricaStatus;
use App\Models\ChaveHistorica;
use App\Services\MeuDanfe\BuscaHistoricaService;
use App\Services\MeuDanfe\MeuDanfeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Consulta no Meu Danfe a fila de chaves do SPED. Um só por vez em todo o sistema: duas buscas paralelas
 * pagariam a mesma chave duas vezes. Quem é marcado durante a busca entra nela, porque a fila é lida chave a chave.
 * Perto do tempo máximo do job, para e volta para a fila.
 */
class BuscarChavesHistoricas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Cada continuação é uma nova tentativa (release); um erro de verdade encerra.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    // Trava enquanto houver fila; se o worker morrer no meio, a rodada de hora em hora volta depois disso.
    public int $uniqueFor = 4 * 3600;

    private const PRAZO_SEGUNDOS = 480;

    public function __construct()
    {
        $this->onQueue(config('sefaz.queue'));
    }

    public function handle(BuscaHistoricaService $service): void
    {
        if (! MeuDanfeClient::configurado()) {
            return;
        }

        $r = $service->buscar(PHP_INT_MAX, now()->addSeconds(self::PRAZO_SEGUNDOS)->toImmutable());

        Log::info('Busca no Meu Danfe', $r);

        // Sem saldo, as restantes esperam a rodada de hora em hora (depois da recarga), não um laço de jobs.
        if (! $r['sem_saldo'] && ChaveHistorica::where('status', ChaveHistoricaStatus::Pendente)->exists()) {
            // Volta para a fila mantendo a trava: nenhuma outra busca começa no intervalo.
            $this->release(5);
        }
    }
}
