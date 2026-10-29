<?php

namespace App\Models;

use App\Enums\ChaveHistoricaStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chave de nota antiga (fora do prazo de download da SEFAZ) na fila do Meu Danfe.
 *
 * @property int $id
 * @property int $company_id
 * @property string $chave
 * @property ChaveHistoricaStatus $status
 * @property int $tentativas
 * @property string|null $ultimo_erro
 * @property CarbonImmutable|null $buscado_em
 * @property string|null $sped_valor VL_DOC do C100
 * @property CarbonImmutable|null $sped_data DT_DOC do C100
 */
#[Fillable(['chave', 'status', 'tentativas', 'ultimo_erro', 'buscado_em', 'sped_valor', 'sped_data'])]
class ChaveHistorica extends Model
{
    protected $table = 'chaves_historicas';

    protected $attributes = ['status' => 'pendente', 'tentativas' => 0];

    protected function casts(): array
    {
        return [
            'status' => ChaveHistoricaStatus::class,
            'tentativas' => 'integer',
            'buscado_em' => 'datetime',
            'sped_valor' => 'decimal:2',
            'sped_data' => 'date',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
