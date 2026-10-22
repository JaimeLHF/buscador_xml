<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Controle da busca pela SEF-SC (modo "certificado do contador"). NSU próprio da SEF-SC, que não tem
 * relação com o NSU do Ambiente Nacional guardado em sync_states.
 *
 * @property int $id
 * @property int $company_id
 * @property string $ult_nsu
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $last_checked_at
 * @property string|null $last_cstat
 * @property string|null $last_xmotivo
 * @property string|null $last_error
 * @property int $consecutive_failures
 */
#[Fillable(['ult_nsu', 'next_check_at', 'last_checked_at', 'last_cstat', 'last_xmotivo', 'last_error', 'consecutive_failures'])]
class SefScSyncState extends Model
{
    protected $attributes = ['ult_nsu' => '0', 'consecutive_failures' => 0];

    protected function casts(): array
    {
        return [
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
