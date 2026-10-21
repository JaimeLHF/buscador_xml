<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $invited_by
 * @property string $email
 * @property OrganizationRole $role
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 */
#[Fillable(['email', 'role', 'token_hash', 'expires_at', 'invited_by', 'accepted_at'])]
#[Hidden(['token_hash'])]
class OrganizationInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->pending()->where('token_hash', hash('sha256', $token))->first();
    }
}
