<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Chave de acesso da API de integração (ERP, contador). Vale para a organização inteira, só leitura.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property string $prefixo
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['name', 'token_hash', 'prefixo', 'created_by', 'last_used_at', 'revoked_at'])]
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
    public static function issue(Organization $organization, string $name, ?User $by): array
    {
        $plain = 'bxml_'.Str::random(40);

        $token = $organization->apiTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefixo' => substr($plain, 0, 12),
            'created_by' => $by?->id,
        ]);

        return [$token, $plain];
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
}
