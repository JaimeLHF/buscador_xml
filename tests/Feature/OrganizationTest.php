<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('creates an owned organization on registration', function () {
    $this->post(route('register.store'), [
        'name' => 'Maria Contadora',
        'email' => 'maria@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->currentOrganization->name)->toBe('Maria Contadora')
        ->and($user->roleIn($user->currentOrganization))->toBe(OrganizationRole::Owner);
});

it('redirects users without organization to the creation page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('companies.index'))->assertRedirect(route('organizations.create'));
});

it('lets a user create another organization and switch to it', function () {
    $user = User::factory()->withOrganization()->create();

    Livewire::actingAs($user)->test('pages::organizations.create')
        ->set('name', 'Escritório Novo')
        ->call('save')
        ->assertRedirect(route('companies.index'));

    expect($user->fresh()->currentOrganization->name)->toBe('Escritório Novo')
        ->and($user->organizations()->count())->toBe(2);
});

it('does not switch to an organization the user does not belong to', function () {
    $user = User::factory()->withOrganization()->create();
    $foreign = Organization::factory()->create();

    Livewire::actingAs($user)->test('organization-switcher')
        ->call('switchTo', $foreign->id)
        ->assertForbidden();
});
