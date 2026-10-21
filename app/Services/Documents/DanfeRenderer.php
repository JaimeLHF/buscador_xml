<?php

namespace App\Services\Documents;

use App\Models\DfeDocument;
use App\Services\Sefaz\XmlStorage;
use Illuminate\Support\Facades\Log;
use NFePHP\DA\NFe\Danfe;
use RuntimeException;
use Throwable;

class DanfeRenderer
{
    public function __construct(private XmlStorage $storage) {}

    public function render(DfeDocument $document): string
    {
        if (! $document->tem_xml_completo || ! $document->xml_path) {
            throw new RuntimeException('O DANFE só pode ser gerado com o XML completo da NF-e.');
        }

        $xml = $this->storage->get($document->xml_path) ?? throw new RuntimeException('XML não encontrado no armazenamento.');

        try {
            return (new Danfe($xml))->render();
        } catch (Throwable $e) {
            // O sped-da falha com XMLs fora do padrão (campos opcionais ausentes); o usuário ainda pode baixar o XML.
            Log::warning('Falha ao gerar DANFE', ['document_id' => $document->id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            throw new RuntimeException('Não foi possível gerar o DANFE deste XML.', previous: $e);
        }
    }
}
