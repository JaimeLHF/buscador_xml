<?php

namespace App\Services\Sefaz;

use App\Services\Sefaz\Exceptions\SefazException;
use DOMDocument;
use DOMElement;

final readonly class RetEvento
{
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public ?string $protocolo,
        public ?string $dataRegistro,
    ) {}

    /** 135 = registrado e vinculado; 136 = registrado sem vínculo; 573 = duplicidade (já registrado antes). */
    public function registrado(): bool
    {
        return in_array($this->cStat, ['135', '136'], true);
    }

    public function duplicado(): bool
    {
        return $this->cStat === '573';
    }

    public static function parse(string $response): self
    {
        $dom = new DOMDocument;

        if ($response === '' || ! @$dom->loadXML($response, LIBXML_NONET)) {
            throw SefazException::invalidResponse();
        }

        // NF-e responde retEnvEvento/retEvento; CT-e 4.00 (evento síncrono) responde retEventoCTe direto.
        $ret = $dom->getElementsByTagName('retEvento')->item(0) ?? $dom->getElementsByTagName('retEventoCTe')->item(0);
        $info = $ret?->getElementsByTagName('infEvento')->item(0);
        $lote = $dom->getElementsByTagName('retEnvEvento')->item(0);

        // Rejeição do lote inteiro (sem retEvento): usa o cStat/xMotivo do retEnvEvento.
        $source = $info ?? $lote;

        if (! $source instanceof DOMElement) {
            throw SefazException::invalidResponse();
        }

        $text = fn (string $tag) => ($n = $source->getElementsByTagName($tag)->item(0)) ? trim($n->textContent) : null;

        return new self(
            cStat: (string) $text('cStat'),
            xMotivo: (string) $text('xMotivo'),
            protocolo: $info ? $text('nProt') : null,
            dataRegistro: $info ? $text('dhRegEvento') : null,
        );
    }
}
