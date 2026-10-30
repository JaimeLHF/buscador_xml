<?php

use App\Enums\DfeSchema;
use App\Enums\ExportFormato;
use App\Enums\ExportStatus;
use App\Enums\Situacao;
use App\Models\Company;
use App\Models\DfeDocument;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withOrganization()->create();
    $this->ruiz = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '24287808000160', 'razao_social' => 'MOVEIS RUIZ LTDA', 'nome_fantasia' => 'FANTASIA']);
    $this->outra = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195', 'razao_social' => 'EMPRESA X LTDA', 'nome_fantasia' => 'FANTASIA']);
});

function notaPara(Company $company, string $fornecedor, string $cnpj): DfeDocument
{
    return $company->documents()->create([
        'tipo' => 'nfe', 'nsu' => '000000000000001', 'schema' => DfeSchema::ResNFe, 'situacao' => Situacao::Autorizada,
        'chave' => str_pad((string) random_int(1, 999999), 44, '4', STR_PAD_LEFT),
        'emitente_nome' => $fornecedor, 'emitente_cnpj' => $cnpj, 'valor_total' => 100, 'data_emissao' => '2026-10-01 12:00:00',
    ]);
}

it('saves the chosen company and shows only its notes and suppliers', function () {
    notaPara($this->ruiz, 'FORNECEDOR DA RUIZ', '11222333000181');
    notaPara($this->outra, 'FORNECEDOR DA OUTRA', '44333222000191');

    Livewire::actingAs($this->user)->test('company-switcher')->call('selecionar', $this->ruiz->id);

    expect($this->user->fresh()->current_company_id)->toBe($this->ruiz->id);

    Livewire::actingAs($this->user->fresh())->test('pages::documents.index')
        ->assertSee('FORNECEDOR DA RUIZ')
        ->assertDontSee('FORNECEDOR DA OUTRA');

    Livewire::actingAs($this->user->fresh())->test('pages::suppliers.index')
        ->assertSee('FORNECEDOR DA RUIZ')
        ->assertDontSee('FORNECEDOR DA OUTRA');

    $this->actingAs($this->user->fresh())->get(route('suppliers.show', '44333222000191'))->assertNotFound();
});

it('goes back to every company with Todas as empresas', function () {
    notaPara($this->ruiz, 'FORNECEDOR DA RUIZ', '11222333000181');
    notaPara($this->outra, 'FORNECEDOR DA OUTRA', '44333222000191');
    $this->user->switchCompany($this->ruiz);

    Livewire::actingAs($this->user)->test('company-switcher')->call('selecionar', null);

    expect($this->user->fresh()->current_company_id)->toBeNull();

    Livewire::actingAs($this->user->fresh())->test('pages::documents.index')
        ->assertSee('FORNECEDOR DA RUIZ')
        ->assertSee('FORNECEDOR DA OUTRA');
});

it('does not let the user pick a company from another organization', function () {
    $alheia = Company::factory()->create();

    Livewire::actingAs($this->user)->test('company-switcher')
        ->call('selecionar', $alheia->id)
        ->assertForbidden();

    expect($this->user->fresh()->current_company_id)->toBeNull();
});

it('lists only the companies of the current organization in the modal', function () {
    Company::factory()->create(['razao_social' => 'CLIENTE DE OUTRO ESCRITORIO']);

    Livewire::actingAs($this->user)->test('company-switcher')
        ->assertSee('MOVEIS RUIZ LTDA')
        ->assertSee('EMPRESA X LTDA')
        ->assertDontSee('CLIENTE DE OUTRO ESCRITORIO');
});

it('clears the chosen company when switching organization', function () {
    $this->user->switchCompany($this->ruiz);
    $outraOrg = Organization::factory()->create();
    $outraOrg->users()->attach($this->user, ['role' => 'owner']);

    $this->user->switchOrganization($outraOrg);

    expect($this->user->fresh()->current_company_id)->toBeNull();
});

it('lists only the downloads requested with the chosen company', function () {
    $org = $this->user->currentOrganization;
    $base = ['user_id' => $this->user->id, 'formato' => ExportFormato::Zip, 'status' => ExportStatus::Done, 'total' => 1];
    $org->exports()->create([...$base, 'filters' => ['company_id' => (string) $this->ruiz->id], 'error' => 'arquivo-da-ruiz']);
    $org->exports()->create([...$base, 'filters' => ['company_id' => (string) $this->outra->id], 'error' => 'arquivo-da-outra']);

    $this->user->switchCompany($this->ruiz);

    Livewire::actingAs($this->user)->test('pages::exports.index')
        ->assertSee('arquivo-da-ruiz')
        ->assertDontSee('arquivo-da-outra');
});

it('moves Empresas out of the sidebar and shows the company card instead', function () {
    $this->user->switchCompany($this->ruiz);

    $this->actingAs($this->user)->get(route('documents.index'))
        ->assertOk()
        ->assertSee('data-test="company-switcher"', false)
        ->assertSee('MOVEIS RUIZ LTDA')
        ->assertSee(__('Gerenciar empresas'));
});

it('shows shortcuts to the chosen company record and SPED page only when a company is chosen', function () {
    $this->actingAs($this->user)->get(route('documents.index'))
        ->assertDontSee('data-test="atalhos-empresa"', false);

    $this->user->switchCompany($this->ruiz);

    $this->actingAs($this->user->fresh())->get(route('documents.index'))
        ->assertSee('data-test="atalhos-empresa"', false)
        ->assertSee(route('companies.show', $this->ruiz), false)
        ->assertSee(route('companies.sped', $this->ruiz), false);
});
