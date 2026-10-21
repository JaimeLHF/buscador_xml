<?php

namespace Tests\Support;

/** Monta respostas retDistDFeInt (envelope SOAP) a partir dos fixtures, com docZip em base64+gzip como a SEFAZ envia. */
final class SefazFixtures
{
    public static function path(string $name): string
    {
        return dirname(__DIR__)."/Fixtures/sefaz/{$name}";
    }

    public static function file(string $name): string
    {
        return (string) file_get_contents(self::path($name));
    }

    /**
     * @param  list<array{nsu: string, schema: string, xml: string}>  $docs
     */
    public static function ret138(string $ultNsu, string $maxNsu, array $docs): string
    {
        $zips = implode('', array_map(
            fn (array $d) => sprintf('<docZip NSU="%s" schema="%s">%s</docZip>', $d['nsu'], $d['schema'], base64_encode(gzencode($d['xml']))),
            $docs,
        ));

        return self::envelope("<cStat>138</cStat><xMotivo>Documento localizado</xMotivo><dhResp>2026-10-21T10:00:00-03:00</dhResp><ultNSU>{$ultNsu}</ultNSU><maxNSU>{$maxNsu}</maxNSU><loteDistDFeInt>{$zips}</loteDistDFeInt>");
    }

    public static function ret(string $cStat, string $xMotivo, ?string $ultNsu = null, ?string $maxNsu = null): string
    {
        $nsus = ($ultNsu ? "<ultNSU>{$ultNsu}</ultNSU>" : '').($maxNsu ? "<maxNSU>{$maxNsu}</maxNSU>" : '');

        return self::envelope("<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo><dhResp>2026-10-21T10:00:00-03:00</dhResp>{$nsus}");
    }

    /**
     * @return array{nsu: string, schema: string, xml: string}
     */
    public static function doc(string $nsu, string $fixture, string $schema): array
    {
        return ['nsu' => $nsu, 'schema' => $schema, 'xml' => self::file($fixture)];
    }

    private static function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe"><nfeDistDFeInteresseResult>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01"><tpAmb>2</tpAmb><verAplic>1.7.6</verAplic>'
            .$body
            .'</retDistDFeInt></nfeDistDFeInteresseResult></nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>';
    }
}
