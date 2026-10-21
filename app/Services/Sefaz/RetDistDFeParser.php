<?php

namespace App\Services\Sefaz;

use App\Enums\DfeSchema;
use App\Services\Sefaz\Exceptions\SefazException;
use DOMDocument;
use DOMElement;

class RetDistDFeParser
{
    /** Aceita o envelope SOAP completo ou só o retDistDFeInt. */
    public function parse(string $response): RetDistDFe
    {
        $dom = new DOMDocument;

        if ($response === '' || ! @$dom->loadXML($response, LIBXML_NONET)) {
            throw SefazException::invalidResponse();
        }

        $ret = $dom->getElementsByTagName('retDistDFeInt')->item(0);

        if (! $ret instanceof DOMElement) {
            throw SefazException::invalidResponse();
        }

        $documents = [];

        foreach ($ret->getElementsByTagName('docZip') as $docZip) {
            $schema = $docZip->getAttribute('schema');

            $documents[] = new DocZip(
                nsu: $this->nsu($docZip->getAttribute('NSU')) ?? '',
                rawSchema: $schema,
                schema: DfeSchema::fromDocZip($schema),
                xml: $this->decode($docZip->textContent),
            );
        }

        return new RetDistDFe(
            cStat: $this->text($ret, 'cStat') ?? '',
            xMotivo: $this->text($ret, 'xMotivo') ?? '',
            ultNsu: $this->nsu($this->text($ret, 'ultNSU')),
            maxNsu: $this->nsu($this->text($ret, 'maxNSU')),
            documents: $documents,
        );
    }

    /** docZip = base64 de um XML compactado com gzip. */
    private function decode(string $content): string
    {
        $binary = base64_decode(trim($content), true);
        $xml = $binary === false ? false : @gzdecode($binary);

        if ($xml === false) {
            throw SefazException::invalidResponse();
        }

        return $xml;
    }

    private function text(DOMElement $parent, string $tag): ?string
    {
        $node = $parent->getElementsByTagName($tag)->item(0);

        return $node ? trim($node->textContent) : null;
    }

    private function nsu(?string $value): ?string
    {
        return $value === null || $value === '' ? null : str_pad($value, 15, '0', STR_PAD_LEFT);
    }
}
