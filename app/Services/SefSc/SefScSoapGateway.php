<?php

namespace App\Services\SefSc;

use App\Enums\Ambiente;
use App\Models\Certificate;
use App\Models\Company;
use App\Services\Sefaz\Exceptions\SefazException;
use DOMDocument;
use NFePHP\Common\Certificate as SpedCertificate;
use NFePHP\Common\Soap\SoapCurl;
use RuntimeException;

/**
 * Web service da SEF-SC para contadores (BT SC-2026/003, NfeDownloadContab, leiaute 2.00).
 * O certificado é o do escritório (TLS mútuo); a SEF-SC confere se o titular é contador da empresa consultada.
 */
class SefScSoapGateway implements SefScGateway
{
    public const NS = 'http://www.satnfe.sef.sc.gov.br/ws/distribuicao-v2';

    public function distribuicao(Certificate $certificate, Company $company, string $ultNsu): string
    {
        // O serviço só existe em produção, e a mesma trava das consultas ao Ambiente Nacional vale aqui.
        if ($company->ambiente !== Ambiente::Producao || ! config('sefaz.allow_production')) {
            throw SefazException::productionBlocked();
        }

        $pedido = self::pedido($company, $ultNsu);
        $soap = new SoapCurl(SpedCertificate::readPfx($certificate->pfx, $certificate->password));
        $soap->timeout(config('sefaz.timeout_seconds'));
        $soap->setEncriptPrivateKey(true);

        return $soap->send(
            config('sef_sc.url'),
            'NfeDownloadContab',
            self::NS.'/NfeDownloadContab',
            SOAP_1_2,
            [],
            [
                'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                'xmlns:xsd' => 'http://www.w3.org/2001/XMLSchema',
                'xmlns:soap' => 'http://www.w3.org/2003/05/soap-envelope',
            ],
            '<NfeDownloadContab xmlns="'.self::NS.'"><pXml>'.$pedido.'</pXml></NfeDownloadContab>',
        );
    }

    /** distNFeSC validado contra o XSD oficial antes do envio: mensagem fora do leiaute conta como tentativa na SEF-SC. */
    public static function pedido(Company $company, string $ultNsu): string
    {
        $xml = '<distNFeSC versao="2.00" xmlns="'.self::NS.'">'
            .'<tpAmb>1</tpAmb><verAplic>BuscadorXML-1.0</verAplic><cUF>42</cUF>'
            ."<CNPJ>{$company->cnpj}</CNPJ>"
            // indXML=1: XML completo; indAtor=3: notas em que a empresa é emitente ou destinatária.
            .'<solRel><indXML>1</indXML><indAtor>3</indAtor><ultNuNSU>'.((int) $ultNsu).'</ultNuNSU></solRel>'
            .'</distNFeSC>';

        $dom = new DOMDocument;
        $dom->loadXML($xml);

        if (! @$dom->schemaValidate(resource_path('schemes/sef-sc/distNFeSC_v2.00.xsd'))) {
            throw new RuntimeException('Pedido à SEF-SC fora do leiaute (CNPJ inválido?).');
        }

        return $xml;
    }
}
