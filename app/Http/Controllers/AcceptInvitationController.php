<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptInvitationController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $invitation = OrganizationInvitation::findByToken($token);
        $user = $request->user();

        // O e-mail já foi verificado (middleware 'verified'), então coincidir o endereço prova que o convite é dele.
        if ($invitation === null || Str::lower($user->email) !== $invitation->email) {
            return redirect()->route('dashboard')->with('error', __('Convite inválido, expirado ou destinado a outro e-mail.'));
        }

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->organization->addMember($user, $invitation->role);
            $invitation->update(['accepted_at' => now()]);
            $user->switchOrganization($invitation->organization);
        });

        return redirect()->route('companies.index')->with('success', __('Você agora faz parte de :org.', ['org' => $invitation->organization->name]));
    }
}
