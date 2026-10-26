<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $join_token
 * @property string|null $join_token_hash
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $webhook_ultimo_status
 * @property CarbonImmutable|null $webhook_ultimo_envio_at
 */
#[Fillable(['name', 'join_token', 'join_token_hash', 'webhook_url', 'webhook_secret', 'webhook_ultimo_status', 'webhook_ultimo_envio_at'])]
#[Hidden(['webhook_secret', 'join_token', 'join_token_hash'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'join_token' => 'encrypted',
            'webhook_ultimo_envio_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<User, $this, Membership> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * Certificado do escritório contábil (e-CNPJ ou e-CPF do contador), usado pelas empresas no modo
     * "certificado do contador (SC)".
     *
     * @return HasOne<Certificate, $this>
     */
    public function officeCertificate(): HasOne
    {
        return $this->hasOne(Certificate::class)->where('active', true)->latestOfMany();
    }

    /** @return HasMany<Certificate, $this> */
    public function officeCertificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** @return HasMany<Export, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    /**
     * Proprietários e administradores, que recebem os alertas.
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        return $this->users()->wherePivotIn('role', [OrganizationRole::Owner->value, OrganizationRole::Admin->value])->get();
    }

    public function addMember(User $user, OrganizationRole $role): void
    {
        $this->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }

    /** Gera um novo link de convite; o anterior deixa de funcionar. */
    public function regenerateJoinLink(): void
    {
        $token = Str::random(40);
        $this->update(['join_token' => $token, 'join_token_hash' => hash('sha256', $token)]);
    }

    public function disableJoinLink(): void
    {
        $this->update(['join_token' => null, 'join_token_hash' => null]);
    }

    public function joinUrl(): ?string
    {
        return $this->join_token === null ? null : route('join', $this->join_token);
    }

    public static function findByJoinToken(string $token): ?self
    {
        return self::query()->where('join_token_hash', hash('sha256', $token))->first();
    }
}
