<?php

namespace App\Models;

use App\Enums\ChaveHistoricaStatus;
use App\Enums\NaturezaCompra;
use App\Services\MeuDanfe\SpedChaves;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $fornecedor_nome NOME do 0150
 * @property string|null $fornecedor_uf
 * @property string|null $cfop CFOP de maior valor no C190
 * @property NaturezaCompra|null $natureza
 * @property string|null $sped_situacao COD_SIT do C100
 * @property array<string, string>|null $sped_impostos icms, icms_st, ipi, pis, cofins, desconto e frete do C100 (só os diferentes de zero)
 */
#[Fillable(['chave', 'status', 'tentativas', 'ultimo_erro', 'buscado_em', 'sped_valor', 'sped_data', 'fornecedor_nome', 'fornecedor_uf', 'cfop', 'natureza', 'sped_situacao', 'sped_impostos'])]
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
            'natureza' => NaturezaCompra::class,
            'sped_impostos' => 'array',
        ];
    }

    /** O SPED escriturou a nota como cancelada, denegada ou inutilizada: consultar seria pagar por nada. */
    public function semNotaNoSped(): bool
    {
        return in_array($this->sped_situacao, SpedChaves::SITUACOES_SEM_NOTA, true);
    }

    public function consultavel(): bool
    {
        return $this->status->selecionavel() && ! $this->semNotaNoSped();
    }

    /** @param  Builder<ChaveHistorica>  $query */
    public function scopeConsultaveis(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('sped_situacao')->orWhereNotIn('sped_situacao', SpedChaves::SITUACOES_SEM_NOTA));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
