<?php

namespace App\Jobs;

use App\Enums\ManifestacaoStatus;
use App\Enums\TipoEvento;
use App\Models\DfeDocument;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Ciência da Operação (210210) automática para liberar o XML completo de um resNFe. */
class SendCienciaOperacao implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Recepção de evento não tem a regra de consumo indevido da distribuição: falhas de rede podem ser retentadas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public DfeDocument $document)
    {
        $this->onQueue(config('sefaz.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(ManifestacaoService $service): void
    {
        $document = $this->document->refresh();

        if ($document->tem_xml_completo || $document->manifestacao_status !== ManifestacaoStatus::CienciaPendente) {
            return;
        }

        try {
            $service->manifestar($document, TipoEvento::CienciaOperacao);
        } catch (ManifestacaoException $e) {
            // Rejeição ou regra de negócio: tentar de novo não muda o resultado.
            $document->update(['manifestacao_status' => ManifestacaoStatus::Erro]);
            $this->fail($e);

            return;
        }

        FetchFullDocument::dispatch($document)->delay(now()->addMinutes(2));
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update(['manifestacao_status' => ManifestacaoStatus::Erro]);
    }
}
