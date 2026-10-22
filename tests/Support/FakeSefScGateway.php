<?php

namespace Tests\Support;

use App\Models\Certificate;
use App\Models\Company;
use App\Services\SefSc\SefScGateway;
use RuntimeException;
use Throwable;

/** Substitui o web service da SEF-SC nos testes: respostas enfileiradas e registro de cada chamada. */
final class FakeSefScGateway implements SefScGateway
{
    /** @var list<string|Throwable> */
    private array $responses = [];

    /** @var list<array{cnpj: string, ultNsu: string, certificado: string}> */
    public array $calls = [];

    public function push(string|Throwable ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function distribuicao(Certificate $certificate, Company $company, string $ultNsu): string
    {
        $this->calls[] = ['cnpj' => $company->cnpj, 'ultNsu' => $ultNsu, 'certificado' => $certificate->cnpj_certificado];

        $response = array_shift($this->responses) ?? throw new RuntimeException("Chamada inesperada à SEF-SC: {$company->cnpj}({$ultNsu})");

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * Resposta retDistNFeSC dentro do envelope SOAP, com o lote em gzip + base64 como a SEF-SC envia.
     *
     * @param  list<array{nsu: int, xml: string}>  $docs
     */
    public static function ret(string $cStat, string $xMotivo, array $docs = [], ?int $ultNsu = null): string
    {
        $lote = '';

        if ($docs !== []) {
            $itens = implode('', array_map(
                fn (array $d) => '<distNFeSC NSU="'.$d['nsu'].'">'.preg_replace('/^<\?xml[^>]*\?>\s*/', '', $d['xml']).'</distNFeSC>',
                $docs,
            ));
            $lote = '<loteDistComp>'.base64_encode(gzencode('<loteDistNFeSC versao="2.00">'.$itens.'</loteDistNFeSC>')).'</loteDistComp>';
        }

        $ult = $ultNsu !== null ? "<ultNuNSURet>{$ultNsu}</ultNuNSURet><qtDfeRet>".count($docs).'</qtDfeRet>' : '';

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<NfeDownloadContabResponse xmlns="http://www.satnfe.sef.sc.gov.br/ws/distribuicao-v2"><NfeDownloadContabResult>'
            .'<retDistNFeSC versao="2.00" xmlns="http://www.satnfe.sef.sc.gov.br/ws/distribuicao-v2"><tpAmb>1</tpAmb><verAplic>SAT</verAplic>'
            ."<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo><dhResp>2026-10-22T10:00:00-03:00</dhResp><cUF>42</cUF><CNPJ>12345678000195</CNPJ>"
            .'<indXML>1</indXML><indAtor>3</indAtor><ultNuNSU>0</ultNuNSU>'.$ult.$lote
            .'</retDistNFeSC></NfeDownloadContabResult></NfeDownloadContabResponse></soap:Body></soap:Envelope>';
    }
}
