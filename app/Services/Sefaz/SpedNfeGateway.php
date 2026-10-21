<?php

namespace App\Services\Sefaz;

use App\Enums\Ambiente;
use App\Models\Company;
use App\Services\Sefaz\Exceptions\SefazException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\NFe\Tools;

class SpedNfeGateway implements SefazGateway
{
    public function distNsu(Company $company, string $ultNsu): string
    {
        return $this->tools($company)->sefazDistDFe((int) $ultNsu);
    }

    public function consNsu(Company $company, string $nsu): string
    {
        return $this->tools($company)->sefazDistDFe(0, (int) $nsu);
    }

    public function consChave(Company $company, string $chave): string
    {
        return $this->tools($company)->sefazDistDFe(0, 0, $chave);
    }

    private function tools(Company $company): Tools
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
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
        ], JSON_THROW_ON_ERROR);

        $spedCertificate = Certificate::readPfx($certificate->pfx, $certificate->password);

        $soap = new SoapCurl($spedCertificate);
        $soap->timeout(config('sefaz.timeout_seconds'));
        // A chave privada é gravada temporariamente em disco para o cURL; assim ela fica cifrada.
        $soap->setEncriptPrivateKey(true);

        $tools = new Tools($config, $spedCertificate);
        $tools->loadSoapClass($soap);

        return $tools;
    }
}
