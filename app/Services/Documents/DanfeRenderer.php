<?php

namespace App\Services\Documents;

use App\Enums\DfeSchema;
use App\Models\DfeDocument;
use App\Services\Sefaz\XmlStorage;
use Illuminate\Support\Facades\Log;
use NFePHP\DA\CTe\Dacte;
use NFePHP\DA\CTe\DacteOS;
use NFePHP\DA\NFe\Danfe;
use RuntimeException;
use Throwable;

class DanfeRenderer
{
    public function __construct(private XmlStorage $storage) {}

    public function render(DfeDocument $document): string
    {
        if (! $document->tem_xml_completo || ! $document->xml_path) {
            throw new RuntimeException('O documento auxiliar só pode ser gerado com o XML completo.');
        }

        $xml = $this->storage->get($document->xml_path) ?? throw new RuntimeException('XML não encontrado no armazenamento.');

        try {
            // Mesmo serviço para os três documentos auxiliares: DANFE (NF-e), DACTE (CT-e) e DACTE OS (CT-e OS).
            return match ($document->schema) {
                DfeSchema::ProcCTe => (new Dacte($xml))->render(),
                DfeSchema::ProcCTeOS => (new DacteOS($xml))->render(),
                default => (new Danfe($xml))->render(),
            };
        } catch (Throwable $e) {
            // O sped-da falha com XMLs fora do padrão (campos opcionais ausentes); o usuário ainda pode baixar o XML.
            Log::warning('Falha ao gerar DANFE', ['document_id' => $document->id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            throw new RuntimeException('Não foi possível gerar o DANFE deste XML.', previous: $e);
        }
    }
}
