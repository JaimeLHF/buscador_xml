<?php

use App\Services\Sefaz\Tools\CteTools;
use App\Services\Sefaz\Tools\NfeTools;
use App\Support\Cnpj;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\ValidatorException;
use NFePHP\Common\Soap\SoapFake;
use NFePHP\NFe\Tools;
use Tests\Support\TestCertificate;

beforeEach(function () {
    $this->cnpj = Cnpj::fromBase('12ABC34501DE')->value;
    $this->certificate = Certificate::readPfx(TestCertificate::pfx($this->cnpj), '1234');
});

function toolsConfig(string $cnpj, string $schemes): string
{
    return json_encode(['atualizacao' => '2026-10-22 12:00:00', 'tpAmb' => 2, 'razaosocial' => 'TESTE', 'cnpj' => $cnpj, 'siglaUF' => 'SC', 'schemes' => $schemes, 'versao' => '4.00']);
}

it('builds the NF-e distribution and manifestation for an alphanumeric CNPJ, sending it unchanged', function () {
    $tools = new NfeTools(toolsConfig($this->cnpj, 'PL_009_V4'), $this->certificate);
    $tools->loadSoapClass(new SoapFake);

    $tools->sefazDistDFe(0);
    expect($tools->lastRequest)->toContain("<CNPJ>{$this->cnpj}</CNPJ>");

    $tools->sefazManifesta('42261011222333000181550010000001231123456788', 210210);
    expect($tools->lastRequest)->toContain("<CNPJ>{$this->cnpj}</CNPJ>")->toContain('<Signature');
});

it('builds the CT-e distribution for an alphanumeric CNPJ', function () {
    $tools = new CteTools(toolsConfig($this->cnpj, 'PL_CTe_400'), $this->certificate);
    $tools->loadSoapClass(new SoapFake);

    $tools->sefazDistDFe(0);
    expect($tools->lastRequest)->toContain("<CNPJ>{$this->cnpj}</CNPJ>");
});

it('still rejects what the stock library rejects: invalid alphanumeric CNPJs', function () {
    $tools = new NfeTools(toolsConfig('12ABC34501DE00', 'PL_009_V4'), $this->certificate);
    $tools->loadSoapClass(new SoapFake);

    expect(fn () => $tools->sefazDistDFe(0))->toThrow(ValidatorException::class);
});

it('documents why the subclass exists: the stock library refuses the alphanumeric CNPJ', function () {
    $tools = new Tools(toolsConfig($this->cnpj, 'PL_009_V4'), $this->certificate);
    $tools->loadSoapClass(new SoapFake);

    expect(fn () => $tools->sefazDistDFe(0))->toThrow(ValidatorException::class);
});
