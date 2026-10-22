<?php

namespace App\Services\SefSc;

use App\Enums\DfeSchema;
use App\Services\Sefaz\DocZip;
use App\Services\Sefaz\Exceptions\SefazException;
use DOMDocument;
use DOMElement;

/** Retorno do NfeDownloadContab: status e o lote (base64 + gzip) com nfeProc e procEventoNFe. */
final readonly class RetDistNFeSc
{
    /**
     * @param  list<DocZip>  $documents
     */
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public ?string $ultNsu,
        public array $documents,
    ) {}

    public function documentosLocalizados(): bool
    {
        return $this->cStat === '118';
    }

    /** Lote cheio: há mais documentos e a continuação pode ser pedida na hora. */
    public function temMais(): bool
    {
        return $this->documentosLocalizados() && count($this->documents) >= config('sef_sc.lote_maximo');
    }

    public static function parse(string $response): self
    {
        $dom = new DOMDocument;

        if ($response === '' || ! @$dom->loadXML($response, LIBXML_NONET)) {
            throw SefazException::invalidResponse();
        }

        $ret = $dom->getElementsByTagName('retDistNFeSC')->item(0) ?? throw SefazException::invalidResponse();
        $text = fn (string $tag) => ($n = $ret->getElementsByTagName($tag)->item(0)) ? trim($n->textContent) : null;

        $lote = $text('loteDistComp');

        return new self(
            cStat: (string) $text('cStat'),
            xMotivo: (string) $text('xMotivo'),
            ultNsu: $text('ultNuNSURet'),
            documents: $lote ? self::documentos($lote) : [],
        );
    }

    /** @return list<DocZip> */
    private static function documentos(string $base64): array
    {
        $xml = @gzdecode((string) base64_decode($base64, true));
        $lote = new DOMDocument;

        if ($xml === false || ! @$lote->loadXML($xml, LIBXML_NONET)) {
            throw SefazException::invalidResponse();
        }

        $docs = [];

        // loadXML bem-sucedido garante o elemento raiz.
        foreach ($lote->documentElement->childNodes as $item) {
            if (! $item instanceof DOMElement || $item->localName !== 'distNFeSC') {
                continue;
            }

            foreach ($item->childNodes as $conteudo) {
                if (! $conteudo instanceof DOMElement) {
                    continue;
                }

                // nfeProc é o mesmo procNFe do Ambiente Nacional; o restante do sistema usa os mesmos nomes de schema.
                $schema = match ($conteudo->localName) {
                    'nfeProc' => DfeSchema::ProcNFe,
                    'procEventoNFe' => DfeSchema::ProcEventoNFe,
                    default => null,
                };

                $docs[] = new DocZip($item->getAttribute('NSU'), $conteudo->localName, $schema, (string) $lote->saveXML($conteudo));
            }
        }

        return $docs;
    }
}
