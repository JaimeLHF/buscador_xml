<?php

use App\Enums\ChaveHistoricaStatus;
use App\Enums\ConferenciaSped;
use App\Models\Company;
use App\Services\MeuDanfe\BuscaHistoricaService;
use App\Services\MeuDanfe\SpedChaves;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\SefazFixtures as F;

const CHAVE_HIST = '42261011222333000181550010000001231123456788';
const MEUDANFE = 'https://api.meudanfe.com.br/v2/';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config(['services.meudanfe.key' => 'teste']);
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
});

/** Importa e marca tudo para a consulta, como quem seleciona as chaves na tela. */
function naFila(Company $company, string $sped): void
{
    SpedChaves::importar($company, $sped);
    $company->chavesHistoricas()->update(['status' => ChaveHistoricaStatus::Pendente]);
}

function sped(): string
{
    return implode("\r\n", [
        '|0000|017|0|01012025|31012025|EMPRESA|12345678000195||SC|||||A|1|',
        '|C100|0|1|F1|55|00|1|123|'.CHAVE_HIST.'|02012025|02012025|100,00|',
        '|C100|0|1|F1|55|00|1|124|'.CHAVE_HIST.'|02012025|02012025|100,00|',
        '|C100|1|0|C1|55|00|1|9|42250124287808000160550010000000091000000090|02012025|02012025|50,00|',
        '|C100|0|0|F2|55|00|1|10|42250124287808000160550010000000101000000105|02012025|02012025|50,00|',
        '|C100|0|1|F3|01|00|1|11||02012025|02012025|50,00|',
    ]);
}

it('queues only third-party NF-e entries from the SPED, once', function () {
    expect(SpedChaves::extrair(sped()))->toBe([CHAVE_HIST]);

    expect(SpedChaves::importar($this->company, sped()))->toBe(['lidas' => 1, 'novas' => 1, 'com_xml' => 0])
        ->and(SpedChaves::importar($this->company, sped()))->toBe(['lidas' => 1, 'novas' => 0, 'com_xml' => 0])
        // Consultar é pago: nada vai para a fila sem alguém marcar.
        ->and($this->company->chavesHistoricas()->sole()->status)->toBe(ChaveHistoricaStatus::Importada);
});

it('skips keys that already have the full XML', function () {
    $this->company->documents()->create(['tipo' => 'nfe', 'chave' => CHAVE_HIST, 'nsu' => '1', 'schema' => 'procNFe', 'situacao' => 'autorizada', 'tem_xml_completo' => true]);

    expect(SpedChaves::importar($this->company, sped())['novas'])->toBe(0);
});

it('polls Meu Danfe until OK and stores the full document', function () {
    naFila($this->company, sped());

    Http::fake([
        MEUDANFE.'fd/add/*' => Http::sequence()->push(['status' => 'SEARCHING'])->push(['status' => 'OK']),
        MEUDANFE.'fd/get/xml/*' => Http::response(['format' => 'XML', 'data' => F::file('procNFe.xml')]),
    ]);

    $r = app(BuscaHistoricaService::class)->buscar(10);

    expect($r['encontradas'])->toBe(1)
        ->and($this->company->documents()->sole())->tem_xml_completo->toBeTrue()->xml_reconstruido->toBeTrue()
        ->and($this->company->chavesHistoricas()->sole()->status)->toBe(ChaveHistoricaStatus::Encontrada);

    Http::assertSent(fn ($req) => $req->hasHeader('Api-Key', 'teste'));
});

it('rejects an XML without authorization protocol', function () {
    naFila($this->company, sped());

    Http::fake([
        MEUDANFE.'fd/add/*' => Http::response(['status' => 'OK']),
        MEUDANFE.'fd/get/xml/*' => Http::response(['format' => 'BASE64', 'data' => base64_encode(preg_replace('#<protNFe.*</protNFe>#s', '', F::file('procNFe.xml')))]),
    ]);

    app(BuscaHistoricaService::class)->buscar(10);

    $item = $this->company->chavesHistoricas()->sole();
    expect($this->company->documents()->count())->toBe(0)
        ->and($item->status)->toBe(ChaveHistoricaStatus::Pendente)
        ->and($item->tentativas)->toBe(1);
});

it('marks not found keys as final', function () {
    naFila($this->company, sped());
    Http::fake([MEUDANFE.'fd/add/*' => Http::response(['status' => 'NOT_FOUND'])]);

    app(BuscaHistoricaService::class)->buscar(10);

    expect($this->company->chavesHistoricas()->sole()->status)->toBe(ChaveHistoricaStatus::NaoEncontrada);
});

it('stops and keeps keys pending when the account has no balance', function () {
    naFila($this->company, sped());
    Http::fake([MEUDANFE.'fd/add/*' => Http::response(['status' => 402], 402)]);

    $r = app(BuscaHistoricaService::class)->buscar(10);

    $item = $this->company->chavesHistoricas()->sole();
    expect($r['sem_saldo'])->toBeTrue()
        ->and($item->status)->toBe(ChaveHistoricaStatus::Pendente)
        ->and($item->tentativas)->toBe(0);
});

/** C100 da chave da fixture procNFe.xml (vNF 1500.75, emitida em 20/10/2026). */
function spedDaFixture(string $data = '20102026', string $valor = '1500,75'): string
{
    return implode("\r\n", [
        '|0000|017|0|01102026|31102026|EMPRESA|12345678000195||SC|||||A|1|',
        '|C100|0|1|F1|55|00|1|123|'.CHAVE_HIST."|{$data}|{$data}|{$valor}|",
    ]);
}

function meuDanfeEntrega(): void
{
    Http::fake([
        MEUDANFE.'fd/add/*' => Http::response(['status' => 'OK']),
        MEUDANFE.'fd/get/xml/*' => Http::response(['format' => 'XML', 'data' => F::file('procNFe.xml')]),
    ]);
}

it('keeps the SPED value and date, and fills them on a key queued before', function () {
    $this->company->chavesHistoricas()->create(['chave' => CHAVE_HIST, 'tentativas' => 2]);

    expect(SpedChaves::importar($this->company, spedDaFixture())['novas'])->toBe(0);

    $item = $this->company->chavesHistoricas()->sole();
    expect($item->sped_valor)->toBe('1500.75')
        ->and($item->sped_data->format('Y-m-d'))->toBe('2026-10-20')
        ->and($item->tentativas)->toBe(2);
});

it('marks the rebuilt XML as matching the SPED when value and date agree', function () {
    naFila($this->company, spedDaFixture());
    meuDanfeEntrega();

    app(BuscaHistoricaService::class)->buscar(10);

    expect($this->company->documents()->sole())
        ->conferencia_sped->toBe(ConferenciaSped::Confere)
        ->conferencia_sped_detalhe->toBeNull();
});

it('flags a divergence and says what differs', function (string $data, string $valor, string $trecho) {
    naFila($this->company, spedDaFixture($data, $valor));
    meuDanfeEntrega();

    app(BuscaHistoricaService::class)->buscar(10);

    $document = $this->company->documents()->sole();
    expect($document->conferencia_sped)->toBe(ConferenciaSped::Diverge)
        ->and($document->conferencia_sped_detalhe)->toContain($trecho)
        // A nota continua gravada: divergir é para alguém olhar, não para sumir.
        ->and($document->tem_xml_completo)->toBeTrue();
})->with([
    'valor' => ['20102026', '1500,74', 'valor 1500.75 no XML e 1500.74 no SPED'],
    'data' => ['19102026', '1500,75', 'emissão 2026-10-20 no XML e 2026-10-19 no SPED'],
]);

it('leaves the check empty when the key has no SPED value or date', function () {
    $this->company->chavesHistoricas()->create(['chave' => CHAVE_HIST]);
    meuDanfeEntrega();

    app(BuscaHistoricaService::class)->buscar(10);

    expect($this->company->documents()->sole()->conferencia_sped)->toBeNull();
});

it('checks XMLs received before the SPED was reimported', function () {
    $this->company->chavesHistoricas()->create(['chave' => CHAVE_HIST]);
    meuDanfeEntrega();
    app(BuscaHistoricaService::class)->buscar(10);

    SpedChaves::importar($this->company, spedDaFixture());

    $this->artisan('historico:conferir', ['company' => $this->company->id])
        ->expectsOutputToContain('Confere: 1 · Diverge: 0')
        ->assertSuccessful();

    expect($this->company->documents()->sole()->conferencia_sped)->toBe(ConferenciaSped::Confere);
});
