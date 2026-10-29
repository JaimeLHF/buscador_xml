<?php

use App\Models\Company;
use App\Models\DfeDocument;
use App\Services\Sefaz\SefazGateway;
use App\Services\SefSc\SefScGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeSefazGateway;
use Tests\Support\FakeSefScGateway;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Rede de segurança: nenhum teste chega à SEFAZ real, mesmo que esqueça de configurar o fake.
        $this->app->instance(SefazGateway::class, new FakeSefazGateway);
        $this->app->instance(SefScGateway::class, new FakeSefScGateway);
        Redis::flushdb();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

function fakeSefaz(): FakeSefazGateway
{
    return app(SefazGateway::class);
}

function fakeSefSc(): FakeSefScGateway
{
    return app(SefScGateway::class);
}

const CNPJ_FORNECEDOR = '11222333000181';
const CNPJ_OUTRA = '11444777000161';

/** NF-e gravada direto no banco, para os testes da API. */
function notaApi(Company $company, string $chave, array $attrs = []): DfeDocument
{
    return $company->documents()->create([
        'tipo' => 'nfe', 'chave' => $chave, 'nsu' => '1', 'schema' => 'procNFe', 'situacao' => 'autorizada',
        'tem_xml_completo' => true, 'emitente_cnpj' => CNPJ_FORNECEDOR, 'data_emissao' => '2026-10-20 13:15:00',
        ...$attrs,
    ]);
}

function chaveApi(int $n, string $aamm = '2610'): string
{
    return '42'.$aamm.CNPJ_FORNECEDOR.'55001'.str_pad((string) $n, 9, '0', STR_PAD_LEFT).'1123456780';
}
