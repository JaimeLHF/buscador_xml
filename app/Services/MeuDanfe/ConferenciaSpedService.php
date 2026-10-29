<?php

namespace App\Services\MeuDanfe;

use App\Enums\ConferenciaSped;
use App\Models\ChaveHistorica;
use App\Models\DfeDocument;
use DOMDocument;

/**
 * Confere o XML remontado pelo Meu Danfe com o C100 do SPED: valor total e data de emissão.
 *
 * Sem a assinatura do emitente, o arquivo não prova sozinho que é a nota autorizada. O SPED foi
 * declarado ao fisco pela própria empresa: se valor e data batem, o XML é a nota que ela escriturou.
 * Chave sem valor nem data do SPED (importada antes desta conferência) fica sem resultado, nunca "confere".
 */
class ConferenciaSpedService
{
    public function conferir(DfeDocument $document, ChaveHistorica $item, string $xml): void
    {
        [$resultado, $detalhe] = $this->comparar($xml, $item);

        $document->update(['conferencia_sped' => $resultado, 'conferencia_sped_detalhe' => $detalhe]);
    }

    /** @return array{0: ?ConferenciaSped, 1: ?string} */
    public function comparar(string $xml, ChaveHistorica $item): array
    {
        if ($item->sped_valor === null && $item->sped_data === null) {
            return [null, null];
        }

        $dom = new DOMDocument;
        @$dom->loadXML($xml, LIBXML_NONET);

        $vNF = $dom->getElementsByTagName('vNF')->item(0)?->nodeValue;
        $dhEmi = $dom->getElementsByTagName('dhEmi')->item(0)?->nodeValue;

        $divergencias = [];

        if ($item->sped_valor !== null && ($vNF === null || self::centavos($vNF) !== self::centavos($item->sped_valor))) {
            $divergencias[] = __('valor :xml no XML e :sped no SPED', ['xml' => $vNF ?? '—', 'sped' => $item->sped_valor]);
        }

        // A data local vem nos 10 primeiros caracteres do dhEmi (o fuso está no fim), que é o que o DT_DOC registra.
        $dataXml = $dhEmi !== null ? substr($dhEmi, 0, 10) : null;
        $dataSped = $item->sped_data?->format('Y-m-d');

        if ($dataSped !== null && $dataXml !== $dataSped) {
            $divergencias[] = __('emissão :xml no XML e :sped no SPED', ['xml' => $dataXml ?? '—', 'sped' => $dataSped]);
        }

        return $divergencias === []
            ? [ConferenciaSped::Confere, null]
            : [ConferenciaSped::Diverge, implode('; ', $divergencias)];
    }

    private static function centavos(string $valor): int
    {
        return (int) round((float) $valor * 100);
    }
}
