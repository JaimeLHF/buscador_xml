<?php

use App\Enums\DfeSchema;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\DfeDocument;
use App\Models\User;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SefazFixtures as F;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->withOrganization()->create();
    $this->org = $this->user->currentOrganization;
    $this->company = Company::factory()->for($this->org)->create(['cnpj' => '12345678000195']);
    [, $this->plain] = ApiToken::issue($this->org, 'Costhub', $this->user);
});

it('tells incoming from issued notes and filters by direction', function () {
    notaApi($this->company, chaveApi(1));
    notaApi($this->company, chaveApi(2), ['emitente_cnpj' => '12345678000195']);
    $this->travel(3)->minutes();

    $todas = $this->withToken($this->plain)->getJson('/api/v1/documents')->assertOk()->json('data');
    expect(collect($todas)->pluck('direcao', 'chave')->all())->toBe([chaveApi(1) => 'entrada', chaveApi(2) => 'saida']);

    expect($this->withToken($this->plain)->getJson('/api/v1/documents?direcao=entrada')->json('data.*.chave'))->toBe([chaveApi(1)])
        ->and($this->withToken($this->plain)->getJson('/api/v1/documents?direcao=saida')->json('data.*.chave'))->toBe([chaveApi(2)]);
    $this->withToken($this->plain)->getJson('/api/v1/documents?direcao=compra')->assertUnprocessable();
});

it('filters by company CNPJ and never shows another organization company', function () {
    $outra = Company::factory()->for($this->org)->create(['cnpj' => CNPJ_FORNECEDOR]);
    notaApi($this->company, chaveApi(1));
    notaApi($outra, chaveApi(2), ['emitente_cnpj' => CNPJ_OUTRA]);
    notaApi(Company::factory()->create(['cnpj' => CNPJ_OUTRA]), chaveApi(3));
    $this->travel(3)->minutes();

    expect($this->withToken($this->plain)->getJson('/api/v1/documents?empresa_cnpj=12345678000195')->json('data.*.chave'))->toBe([chaveApi(1)])
        ->and($this->withToken($this->plain)->getJson('/api/v1/documents?empresa_cnpj='.CNPJ_FORNECEDOR)->json('data.*.chave'))->toBe([chaveApi(2)])
        ->and($this->withToken($this->plain)->getJson('/api/v1/documents?empresa_cnpj='.CNPJ_OUTRA)->assertOk()->json('data'))->toBe([]);

    $this->withToken($this->plain)->getJson('/api/v1/documents?empresa_cnpj=12345678000190')->assertUnprocessable();
    $this->withToken($this->plain)->getJson('/api/v1/documents?empresa_cnpj=12.345.678/0001-95')->assertUnprocessable();
});

it('always returns a resume cursor that continues without repeating notes', function () {
    notaApi($this->company, chaveApi(1));
    notaApi($this->company, chaveApi(2));
    $this->travel(3)->minutes();

    $primeira = $this->withToken($this->plain)->getJson('/api/v1/documents?por_pagina=1')->assertOk();
    expect($primeira->json('data.*.chave'))->toBe([chaveApi(1)])->and($primeira->json('cursor_retomada'))->not->toBeNull();

    $ultima = $this->withToken($this->plain)->getJson('/api/v1/documents?por_pagina=1&cursor='.$primeira->json('cursor_retomada'));
    expect($ultima->json('data.*.chave'))->toBe([chaveApi(2)])
        ->and($ultima->json('proximo_cursor'))->toBeNull()
        ->and($ultima->json('cursor_retomada'))->not->toBeNull();

    $vazia = $this->withToken($this->plain)->getJson('/api/v1/documents?cursor='.$ultima->json('cursor_retomada'));
    expect($vazia->json('data'))->toBe([])->and($vazia->json('cursor_retomada'))->toBe($ultima->json('cursor_retomada'));

    notaApi($this->company, chaveApi(3));
    $this->travel(3)->minutes();

    expect($this->withToken($this->plain)->getJson('/api/v1/documents?cursor='.$vazia->json('cursor_retomada'))->json('data.*.chave'))->toBe([chaveApi(3)]);
});

it('holds back recent rows so a late commit with an older timestamp is not skipped', function () {
    notaApi($this->company, chaveApi(1));
    $this->travel(1)->minutes();

    // A nota de 1 minuto atrás ainda está dentro da janela: o cursor não avança sobre ela.
    $antes = $this->withToken($this->plain)->getJson('/api/v1/documents')->assertOk();
    expect($antes->json('data'))->toBe([])->and($antes->json('cursor_retomada'))->toBeNull();

    // Linha de uma transação que fez commit depois da leitura, com updated_at anterior ao da primeira nota.
    $this->travel(-30)->seconds();
    notaApi($this->company, chaveApi(2));
    DfeDocument::where('chave', chaveApi(2))->toBase()->update(['updated_at' => now()->subMinute()]);
    $this->travel(3)->minutes();

    expect($this->withToken($this->plain)->getJson('/api/v1/documents')->json('data.*.chave'))->toBe([chaveApi(2), chaveApi(1)]);
});

it('brings a note already read back to the feed when it is cancelled', function () {
    $processar = fn (string $file, string $schema, DfeSchema $s) => app(DocumentProcessor::class)->process($this->company, new DocZip('1', $schema, $s, F::file($file)));

    $processar('procNFe.xml', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe);
    $this->travel(3)->minutes();
    $lida = $this->withToken($this->plain)->getJson('/api/v1/documents')->assertOk();
    expect($lida->json('data.0.situacao'))->toBe('autorizada');

    $processar('procEventoNFe_cancelamento.xml', 'procEventoNFe_v1.00.xsd', DfeSchema::ProcEventoNFe);
    $this->travel(3)->minutes();

    $depois = $this->withToken($this->plain)->getJson('/api/v1/documents?cursor='.$lida->json('cursor_retomada'))->assertOk();
    expect($depois->json('data'))->toHaveCount(1)
        ->and($depois->json('data.0.chave'))->toBe($lida->json('data.0.chave'))
        ->and($depois->json('data.0.situacao'))->toBe('cancelada');
});
