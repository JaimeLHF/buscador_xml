<?php

namespace App\Models;

use App\Enums\DocumentoTipo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property DocumentoTipo $tipo
 * @property string $ult_nsu
 * @property string|null $max_nsu
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $last_checked_at
 * @property string|null $last_cstat
 * @property string|null $last_xmotivo
 * @property string|null $last_error
 * @property int $consecutive_656
 * @property int $consecutive_failures
 */
#[Fillable(['tipo', 'ult_nsu', 'max_nsu', 'next_check_at', 'last_checked_at', 'last_cstat', 'last_xmotivo', 'last_error', 'consecutive_656', 'consecutive_failures'])]
class SyncState extends Model
{
    protected function casts(): array
    {
        return [
            'tipo' => DocumentoTipo::class,
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'consecutive_656' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
