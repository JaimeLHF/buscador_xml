<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withOrganization()->create();
});

it('fills company data from BrasilAPI when the CNPJ is typed', function () {
    Http::fake([
        'brasilapi.com.br/*' => Http::response([
            'razao_social' => 'PETROLEO BRASILEIRO S A PETROBRAS',
            'nome_fantasia' => 'PETROBRAS - EDISE',
            'uf' => 'RJ',
            'descricao_situacao_cadastral' => 'ATIVA',
        ]),
    ]);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '33000167000101')
        ->assertSet('cnpj', '33.000.167/0001-01')
        ->assertSet('razao_social', 'PETROLEO BRASILEIRO S A PETROBRAS')
        ->assertSet('nome_fantasia', 'PETROBRAS - EDISE')
        ->assertSet('uf', 'RJ')
        ->assertSet('lookupVariant', 'success');
});

it('falls back to CNPJá when BrasilAPI fails', function () {
    Http::fake([
        'brasilapi.com.br/*' => Http::response(status: 503),
        'open.cnpja.com/*' => Http::response([
            'company' => ['name' => 'EMPRESA RESERVA LTDA'],
            'alias' => null,
            'address' => ['state' => 'SC'],
            'status' => ['id' => 2, 'text' => 'Ativa'],
        ]),
    ]);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '33000167000101')
        ->assertSet('razao_social', 'EMPRESA RESERVA LTDA')
        ->assertSet('uf', 'SC');
});

it('warns when the CNPJ is not found', function () {
    Http::fake(['brasilapi.com.br/*' => Http::response(status: 404)]);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '11222333000181')
        ->assertSet('razao_social', '')
        ->assertSet('lookupVariant', 'warning');
});

it('warns when the company is inactive at Receita Federal', function () {
    Http::fake(['brasilapi.com.br/*' => Http::response([
        'razao_social' => 'BAIXADA LTDA', 'nome_fantasia' => '', 'uf' => 'SP', 'descricao_situacao_cadastral' => 'BAIXADA',
    ])]);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '33000167000101')
        ->assertSet('lookupVariant', 'danger');
});

it('does not call any API for invalid CNPJs or when editing without changing it', function () {
    Http::fake();
    $company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '33000167000101']);

    Livewire::actingAs($this->user)->test('pages::companies.form')->set('cnpj', '123');
    Livewire::actingAs($this->user)->test('pages::companies.form', ['company' => $company])->set('cnpj', '33000167000101');

    Http::assertNothingSent();
});

it('caches lookups', function () {
    config(['cache.default' => 'array']);
    Http::fake(['brasilapi.com.br/*' => Http::response(['razao_social' => 'X LTDA', 'uf' => 'SP'])]);

    foreach ([1, 2] as $_) {
        Livewire::actingAs($this->user)->test('pages::companies.form')->set('cnpj', '33000167000101');
    }

    Http::assertSentCount(1);
});
