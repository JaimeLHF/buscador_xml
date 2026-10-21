<?php

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

class ExportPolicy
{
    /** Exportações são pessoais: só quem pediu baixa, e só enquanto ainda for membro da organização. */
    public function download(User $user, Export $export): bool
    {
        return $export->user_id === $user->id && $user->belongsToOrganization($export->organization_id);
    }
}
