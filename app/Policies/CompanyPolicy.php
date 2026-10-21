<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_organization_id !== null;
    }

    /** Só a organização ativa: evita misturar dados de clientes diferentes na mesma tela. */
    public function view(User $user, Company $company): bool
    {
        return $company->organization_id === $user->current_organization_id
            && $user->belongsToOrganization($company->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->current_organization_id !== null
            && (bool) $user->roleIn($user->current_organization_id)?->canManage();
    }

    public function update(User $user, Company $company): bool
    {
        return $this->view($user, $company)
            && (bool) $user->roleIn($company->organization_id)?->canManage();
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function manageCertificate(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }
}
