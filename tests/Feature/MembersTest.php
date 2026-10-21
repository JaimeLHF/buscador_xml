<?php

use App\Actions\Organizations\InviteMember;
use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvited;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->withOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
});

it('invites by email and the invited user joins after accepting', function () {
    Notification::fake();

    Livewire::actingAs($this->owner)->test('pages::organizations.members')
        ->set('email', 'Nova@Example.com')
        ->set('role', 'admin')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = OrganizationInvitation::sole();
    expect($invitation->email)->toBe('nova@example.com')->and($invitation->token_hash)->toHaveLength(64);

    $token = null;
    Notification::assertSentOnDemand(OrganizationInvited::class, function (OrganizationInvited $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $invitee = User::factory()->withOrganization()->create(['email' => 'nova@example.com']);
    $this->actingAs($invitee)->get(route('invitations.accept', $token))->assertRedirect(route('companies.index'));

    expect($invitee->fresh()->current_organization_id)->toBe($this->organization->id)
        ->and($invitee->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects invitations for another email, expired or reused', function () {
    Notification::fake();
    app(InviteMember::class)->handle($this->organization, $this->owner, 'alvo@example.com', OrganizationRole::Member);
    $token = null;
    Notification::assertSentOnDemand(OrganizationInvited::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $intruder = User::factory()->withOrganization()->create(['email' => 'intruso@example.com']);
    $this->actingAs($intruder)->get(route('invitations.accept', $token))->assertSessionHas('error');
    expect($intruder->belongsToOrganization($this->organization))->toBeFalse();

    $this->travel(8)->days();
    $target = User::factory()->withOrganization()->create(['email' => 'alvo@example.com']);
    $this->actingAs($target)->get(route('invitations.accept', $token))->assertSessionHas('error');
});

it('lets managers change roles and remove members but protects the owner', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->organization);

    Livewire::actingAs($this->owner)->test('pages::organizations.members')
        ->call('changeRole', $member->id, 'admin');
    expect($member->roleIn($this->organization))->toBe(OrganizationRole::Admin);

    Livewire::actingAs($member)->test('pages::organizations.members')
        ->call('remove', $this->owner->id)
        ->assertForbidden();

    Livewire::actingAs($this->owner)->test('pages::organizations.members')
        ->call('remove', $member->id);
    expect($member->belongsToOrganization($this->organization))->toBeFalse();
});

it('forbids members from inviting', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->organization);

    Livewire::actingAs($member)->test('pages::organizations.members')
        ->set('email', 'x@example.com')
        ->call('invite')
        ->assertForbidden();
});
