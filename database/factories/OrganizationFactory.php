<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    public function withMember(User $user, OrganizationRole $role = OrganizationRole::Owner): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role): void {
            $organization->addMember($user, $role);

            if ($user->current_organization_id === null) {
                $user->switchOrganization($organization);
            }
        });
    }
}
