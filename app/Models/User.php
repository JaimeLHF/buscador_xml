<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_organization_id
 * @property-read Organization|null $currentOrganization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Organization, $this, Membership> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsTo<Organization, $this> */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function roleIn(Organization|int $organization): ?OrganizationRole
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        /** @var Membership|null $membership */
        $membership = $this->organizations()->whereKey($id)->first()?->pivot;

        return $membership?->role;
    }

    /** Dono ou administrador da organização ativa (a que aparece no seletor). */
    public function managesCurrentOrganization(): bool
    {
        return $this->current_organization_id !== null && (bool) $this->roleIn($this->current_organization_id)?->canManage();
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        return $this->roleIn($organization) !== null;
    }

    public function switchOrganization(Organization $organization): void
    {
        abort_unless($this->belongsToOrganization($organization), 403);

        $this->forceFill(['current_organization_id' => $organization->id])->save();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
