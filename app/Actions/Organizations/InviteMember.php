<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteMember
{
    public function handle(Organization $organization, User $inviter, string $email, OrganizationRole $role): void
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $organization->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

        $organization->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'invited_by' => $inviter->id,
        ]);

        Notification::route('mail', $email)->notify(new OrganizationInvited($organization, $inviter, $token));
    }
}
