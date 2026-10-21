<?php

use App\Enums\DocumentoTipo;
use App\Enums\OrganizationRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withOrganization()->create();
    $this->organization = $this->user->currentOrganization;
});

it('creates a company with alphanumeric CNPJ and an NF-e sync state', function () {
    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '12.abc.345/01de-35')
        ->set('razao_social', 'Empresa Alfa LTDA')
        ->set('uf', 'SC')
        ->set('ambiente', 2)
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::sole();

    expect($company->cnpj)->toBe('12ABC34501DE35')
        ->and($company->organization_id)->toBe($this->organization->id)
        ->and($company->syncStates()->sole()->tipo)->toBe(DocumentoTipo::Nfe)
        ->and($company->syncStates()->sole()->ult_nsu)->toBe('000000000000000');
});

it('rejects invalid and duplicated CNPJs', function () {
    Company::factory()->for($this->organization)->create(['cnpj' => '11222333000181']);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '11.222.333/0001-82')->set('razao_social', 'X')->set('uf', 'SP')
        ->call('save')
        ->assertHasErrors(['cnpj']);

    Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '11.222.333/0001-81')->set('razao_social', 'X')->set('uf', 'SP')
        ->call('save')
        ->assertHasErrors(['cnpj']);
});

it('lists only companies of the current organization', function () {
    $mine = Company::factory()->for($this->organization)->create(['razao_social' => 'Minha Empresa']);
    $other = Company::factory()->create(['razao_social' => 'Empresa de Outro']);

    $this->actingAs($this->user)->get(route('companies.index'))
        ->assertOk()
        ->assertSee('Minha Empresa')
        ->assertDontSee('Empresa de Outro');
});

it('forbids viewing or editing companies from other organizations', function () {
    $other = Company::factory()->create();

    $this->actingAs($this->user)->get(route('companies.show', $other))->assertForbidden();
    $this->actingAs($this->user)->get(route('companies.edit', $other))->assertForbidden();
});

it('forbids members from creating or editing companies', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->organization);
    $company = Company::factory()->for($this->organization)->create();

    $this->actingAs($member)->get(route('companies.show', $company))->assertOk();
    $this->actingAs($member)->get(route('companies.create'))->assertForbidden();
    $this->actingAs($member)->get(route('companies.edit', $company))->assertForbidden();
});
