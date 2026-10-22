<?php

namespace App\Services\Sefaz;

use App\Enums\Ambiente;
use App\Enums\DocumentoTipo;
use App\Enums\TipoEvento;
use App\Models\Company;
use App\Services\Sefaz\Exceptions\SefazException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\CTe\Tools as CteTools;
use NFePHP\NFe\Tools;

class SpedNfeGateway implements SefazGateway
{
    public function distNsu(Company $company, string $ultNsu, DocumentoTipo $tipo = DocumentoTipo::Nfe): string
    {
        return match ($tipo) {
            DocumentoTipo::Nfe => $this->tools($company)->sefazDistDFe((int) $ultNsu),
            DocumentoTipo::Cte => $this->cteTools($company)->sefazDistDFe((int) $ultNsu),
        };
    }

    public function consNsu(Company $company, string $nsu): string
    {
        return $this->tools($company)->sefazDistDFe(0, (int) $nsu);
    }

    public function consChave(Company $company, string $chave): string
    {
        return $this->tools($company)->sefazDistDFe(0, 0, $chave);
    }

    public function manifestar(Company $company, string $chave, TipoEvento $tipo, int $nSeqEvento, string $xJust = ''): EventoResponse
    {
        $tools = $this->tools($company);
        $response = $tools->sefazManifesta($chave, (int) $tipo->value, $xJust, $nSeqEvento);

        return new EventoResponse($tools->lastRequest, $response);
    }

    private function tools(Company $company): Tools
    {
        [$config, $certificate, $soap] = $this->prepare($company, 'PL_009_V4');

        $tools = new Tools($config, $certificate);
        $tools->loadSoapClass($soap);

        return $tools;
    }

    private function cteTools(Company $company): CteTools
    {
        [$config, $certificate, $soap] = $this->prepare($company, 'PL_CTe_400');

        $tools = new CteTools($config, $certificate);
        $tools->loadSoapClass($soap);

        return $tools;
    }

    /** @return array{string, Certificate, SoapCurl} */
    private function prepare(Company $company, string $schemes): array
    {
        if ($company->ambiente === Ambiente::Producao && ! config('sefaz.allow_production')) {
            throw SefazException::productionBlocked();
        }

        $certificate = $company->activeCertificate;

        if ($certificate === null || $certificate->isExpired()) {
            throw SefazException::withoutCertificate();
        }

        $config = json_encode([
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => $company->ambiente->value,
            'razaosocial' => $company->razao_social,
            'cnpj' => $company->cnpj,
            'siglaUF' => $company->uf->value,
            'schemes' => $schemes,
            'versao' => '4.00',
        ], JSON_THROW_ON_ERROR);

        $spedCertificate = Certificate::readPfx($certificate->pfx, $certificate->password);

        $soap = new SoapCurl($spedCertificate);
        $soap->timeout(config('sefaz.timeout_seconds'));
        // A chave privada é gravada temporariamente em disco para o cURL; assim ela fica cifrada.
        $soap->setEncriptPrivateKey(true);

        return [$config, $spedCertificate, $soap];
    }
}
