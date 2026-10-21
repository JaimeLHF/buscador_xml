<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function handle(User $owner, string $name): Organization
    {
        return DB::transaction(function () use ($owner, $name): Organization {
            $organization = Organization::create(['name' => $name]);
            $organization->addMember($owner, OrganizationRole::Owner);
            $owner->switchOrganization($organization);

            return $organization;
        });
    }
}
