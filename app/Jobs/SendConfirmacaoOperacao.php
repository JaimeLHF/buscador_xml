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

/** Confirmação da Operação (210200) solicitada em lote pelo painel; libera o XML completo de resumos antigos. */
class SendConfirmacaoOperacao implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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

        if ($document->manifestacao_status !== ManifestacaoStatus::ConfirmacaoPendente) {
            return;
        }

        try {
            $service->manifestar($document, TipoEvento::ConfirmacaoOperacao);
        } catch (ManifestacaoException $e) {
            $document->update(['manifestacao_status' => ManifestacaoStatus::Erro]);
            $this->fail($e);

            return;
        }

        if (! $document->tem_xml_completo) {
            FetchFullDocument::dispatch($document)->delay(now()->addMinutes(2));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update(['manifestacao_status' => ManifestacaoStatus::Erro]);
    }
}
