<?php

namespace App\Models;

use App\Enums\SefazServico;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonImmutable;

/**
 * @property int $id
 * @property int $company_id
 * @property SefazServico $servico
 * @property int $tp_amb
 * @property string|null $cstat
 * @property string|null $xmotivo
 * @property string|null $ult_nsu_enviado
 * @property string|null $ult_nsu_retornado
 * @property string|null $max_nsu
 * @property string|null $chave
 * @property int $documentos
 * @property int $duracao_ms
 * @property string|null $erro
 * @property CarbonImmutable $created_at
 */
#[Fillable(['servico', 'tp_amb', 'cstat', 'xmotivo', 'ult_nsu_enviado', 'ult_nsu_retornado', 'max_nsu', 'chave', 'documentos', 'duracao_ms', 'erro'])]
class SefazRequest extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'servico' => SefazServico::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
