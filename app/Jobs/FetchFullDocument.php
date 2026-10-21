<?php

namespace App\Jobs;

use App\Models\DfeDocument;
use App\Services\Sefaz\ConsultaChaveService;
use App\Services\Sefaz\Exceptions\SefazException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Após a Ciência, busca o procNFe por chave (consChNFe). Se o limite de 20/hora do CNPJ estiver esgotado,
 * não faz nada: o XML completo chega de qualquer forma na próxima distribuição por NSU.
 */
class FetchFullDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public DfeDocument $document)
    {
        $this->onQueue(config('sefaz.queue'));
    }

    public function handle(ConsultaChaveService $service): void
    {
        $document = $this->document->refresh();

        if ($document->tem_xml_completo) {
            return;
        }

        try {
            $service->consultar($document->company, $document->chave);
        } catch (SefazException $e) {
            Log::info('XML completo fica para a próxima distribuição', ['document_id' => $document->id, 'motivo' => $e->getMessage()]);
        }
    }
}
