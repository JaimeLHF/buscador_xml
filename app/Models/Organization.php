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

/**
 * @property int $id
 * @property string $name
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $webhook_ultimo_status
 * @property CarbonImmutable|null $webhook_ultimo_envio_at
 */
#[Fillable(['name', 'webhook_url', 'webhook_secret', 'webhook_ultimo_status', 'webhook_ultimo_envio_at'])]
#[Hidden(['webhook_secret'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
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
}
