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

    /** Pedido envEvento como o sped-nfe gera (sem a assinatura, irrelevante para montar o procEventoNFe). */
    public static function envEvento(string $cnpj, string $chave, string $tpEvento, int $seq = 1): string
    {
        $seqPad = str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

        return '<envEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>202610211200001</idLote>'
            ."<evento xmlns=\"http://www.portalfiscal.inf.br/nfe\" versao=\"1.00\"><infEvento Id=\"ID{$tpEvento}{$chave}{$seqPad}\"><cOrgao>91</cOrgao><tpAmb>2</tpAmb>"
            ."<CNPJ>{$cnpj}</CNPJ><chNFe>{$chave}</chNFe><dhEvento>2026-10-21T12:00:00-03:00</dhEvento><tpEvento>{$tpEvento}</tpEvento>"
            ."<nSeqEvento>{$seq}</nSeqEvento><verEvento>1.00</verEvento><detEvento versao=\"1.00\"><descEvento>Ciencia da Operacao</descEvento></detEvento></infEvento></evento>"
            .'</envEvento>';
    }

    public static function retEvento(string $cStat, string $xMotivo, string $chave, string $tpEvento = '210210', int $seq = 1): string
    {
        $nProt = in_array($cStat, ['135', '136'], true) ? '<nProt>891260000012345</nProt>' : '';

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoNFResult xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4">'
            .'<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>202610211200001</idLote><tpAmb>2</tpAmb><verAplic>AN_1.5.0</verAplic><cOrgao>91</cOrgao><cStat>128</cStat><xMotivo>Lote de evento processado</xMotivo>'
            ."<retEvento versao=\"1.00\"><infEvento><tpAmb>2</tpAmb><verAplic>AN_1.5.0</verAplic><cOrgao>91</cOrgao><cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            ."<chNFe>{$chave}</chNFe><tpEvento>{$tpEvento}</tpEvento><xEvento>Ciencia da Operacao</xEvento><nSeqEvento>{$seq}</nSeqEvento><dhRegEvento>2026-10-21T12:00:05-03:00</dhRegEvento>{$nProt}</infEvento></retEvento>"
            .'</retEnvEvento></nfeRecepcaoEventoNFResult></soap:Body></soap:Envelope>';
    }

    public static function eventoCTe(string $cnpj, string $chave, string $tpEvento, int $seq = 1): string
    {
        $seqPad = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return "<eventoCTe xmlns=\"http://www.portalfiscal.inf.br/cte\" versao=\"4.00\"><infEvento Id=\"ID{$tpEvento}{$chave}{$seqPad}\"><cOrgao>42</cOrgao><tpAmb>2</tpAmb>"
            ."<CNPJ>{$cnpj}</CNPJ><chCTe>{$chave}</chCTe><dhEvento>2026-10-21T12:00:00-03:00</dhEvento><tpEvento>{$tpEvento}</tpEvento><nSeqEvento>{$seqPad}</nSeqEvento>"
            .'<detEvento versaoEvento="4.00"><evPrestDesacordo><descEvento>Prestacao do Servico em Desacordo</descEvento><indDesacordoOper>1</indDesacordoOper><xObs>Frete cobrado em duplicidade</xObs></evPrestDesacordo></detEvento></infEvento></eventoCTe>';
    }

    public static function retEventoCTe(string $cStat, string $xMotivo, string $chave, string $tpEvento = '610110', int $seq = 1): string
    {
        $nProt = in_array($cStat, ['135', '136'], true) ? '<nProt>342260000054321</nProt>' : '';

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<cteRecepcaoEventoResult xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeRecepcaoEventoV4">'
            ."<retEventoCTe xmlns=\"http://www.portalfiscal.inf.br/cte\" versao=\"4.00\"><infEvento><tpAmb>2</tpAmb><verAplic>SVRS</verAplic><cOrgao>42</cOrgao><cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            ."<chCTe>{$chave}</chCTe><tpEvento>{$tpEvento}</tpEvento><xEvento>Prestacao do Servico em Desacordo</xEvento><nSeqEvento>{$seq}</nSeqEvento><dhRegEvento>2026-10-21T12:00:05-03:00</dhRegEvento>{$nProt}</infEvento></retEventoCTe>"
            .'</cteRecepcaoEventoResult></soap:Body></soap:Envelope>';
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
