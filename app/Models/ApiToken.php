<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Chave de acesso da API de integração, só leitura. As conexões criadas no painel admin valem para uma empresa
 * (company_id); as antigas, sem empresa, valem para a organização inteira.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $company_id
 * @property int|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property string $prefixo
 * @property CarbonImmutable|null $last_used_at
 * @property int $total_chamadas
 * @property string|null $ultimo_ip
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['name', 'company_id', 'token_hash', 'prefixo', 'created_by', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Cria a chave e devolve o token em claro, que não fica guardado em lugar nenhum.
     *
     * @return array{ApiToken, string}
     */
    public static function issue(Organization $organization, string $name, ?User $by, ?Company $company = null): array
    {
        abort_if($company !== null && $company->organization_id !== $organization->id, 422);

        $plain = 'bxml_'.Str::random(40);

        $token = $organization->apiTokens()->create([
            'name' => $name,
            'company_id' => $company?->id,
            'token_hash' => hash('sha256', $plain),
            'prefixo' => substr($plain, 0, 12),
            'created_by' => $by?->id,
        ]);

        return [$token, $plain];
    }

    /**
     * Conexão de uma empresa (painel admin): a chave só enxerga as notas dela.
     *
     * @return array{ApiToken, string}
     */
    public static function issueForCompany(Company $company, string $name, ?User $by): array
    {
        return self::issue($company->organization, $name, $by, $company);
    }

    /**
     * Empresas que a chave enxerga: a da conexão ou, nas chaves antigas, todas da organização.
     *
     * @return Builder<Company>
     */
    public function companies(): Builder
    {
        return Company::query()
            ->where('organization_id', $this->organization_id)
            ->when($this->company_id, fn (Builder $q, int $id) => $q->whereKey($id));
    }

    public static function findActive(string $plain): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    /**
     * @param  Builder<ApiToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ApiTokenAcesso, $this> */
    public function acessos(): HasMany
    {
        return $this->hasMany(ApiTokenAcesso::class);
    }
}
