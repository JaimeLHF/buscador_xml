<?php

use App\Enums\ChaveHistoricaStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\MeuDanfe\BuscaHistoricaService;
use App\Services\MeuDanfe\SpedChaves;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;

const CHAVE_HIST = '42261011222333000181550010000001231123456788';
const MEUDANFE = 'https://api.meudanfe.com.br/v2/';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config(['services.meudanfe.key' => 'teste']);
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
});

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

    expect(SpedChaves::importar($this->company, sped()))->toBe(['lidas' => 1, 'novas' => 1])
        ->and(SpedChaves::importar($this->company, sped()))->toBe(['lidas' => 1, 'novas' => 0]);
});

it('skips keys that already have the full XML', function () {
    $this->company->documents()->create(['tipo' => 'nfe', 'chave' => CHAVE_HIST, 'nsu' => '1', 'schema' => 'procNFe', 'situacao' => 'autorizada', 'tem_xml_completo' => true]);

    expect(SpedChaves::importar($this->company, sped())['novas'])->toBe(0);
});

it('polls Meu Danfe until OK and stores the full document', function () {
    SpedChaves::importar($this->company, sped());

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
    SpedChaves::importar($this->company, sped());

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
    SpedChaves::importar($this->company, sped());
    Http::fake([MEUDANFE.'fd/add/*' => Http::response(['status' => 'NOT_FOUND'])]);

    app(BuscaHistoricaService::class)->buscar(10);

    expect($this->company->chavesHistoricas()->sole()->status)->toBe(ChaveHistoricaStatus::NaoEncontrada);
});

it('stops and keeps keys pending when the account has no balance', function () {
    SpedChaves::importar($this->company, sped());
    Http::fake([MEUDANFE.'fd/add/*' => Http::response(['status' => 402], 402)]);

    $r = app(BuscaHistoricaService::class)->buscar(10);

    $item = $this->company->chavesHistoricas()->sole();
    expect($r['sem_saldo'])->toBeTrue()
        ->and($item->status)->toBe(ChaveHistoricaStatus::Pendente)
        ->and($item->tentativas)->toBe(0);
});

it('imports SPED files from the company page', function () {
    $user = User::factory()->withOrganization()->create();
    $company = Company::factory()->for($user->currentOrganization)->create();

    Livewire::actingAs($user)->test('pages::companies.show', ['company' => $company])
        ->set('speds', [UploadedFile::fake()->createWithContent('sped.txt', sped())])
        ->call('importarSped')
        ->assertHasNoErrors()
        ->assertSee('1 notas de entrada encontradas no SPED, 1 novas na fila.');

    expect($company->chavesHistoricas()->count())->toBe(1);
});
