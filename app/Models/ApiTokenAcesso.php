<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada feita à API com uma chave: o que saiu do FinderXML, para quem e de onde.
 *
 * @property int $id
 * @property int $api_token_id
 * @property string $rota
 * @property string|null $chave
 * @property int|null $quantidade
 * @property int $status
 * @property string|null $ip
 * @property CarbonImmutable $created_at
 */
#[Fillable(['rota', 'chave', 'quantidade', 'status', 'ip'])]
class ApiTokenAcesso extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Quanto tempo o registro de chamadas fica guardado. */
    public const DIAS_GUARDADOS = 180;

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::DIAS_GUARDADOS));
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }
}
