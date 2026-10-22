<?php

namespace App\Models;

use App\Enums\DfeSchema;
use App\Enums\DocumentoTipo;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property DocumentoTipo $tipo
 * @property string $chave
 * @property string $nsu
 * @property DfeSchema $schema
 * @property string|null $emitente_cnpj
 * @property string|null $emitente_nome
 * @property CarbonImmutable|null $data_emissao
 * @property string|null $valor_total
 * @property Situacao $situacao
 * @property bool $tem_xml_completo
 * @property ManifestacaoStatus|null $manifestacao_status
 * @property string|null $xml_path
 * @property string|null $resumo_xml_path
 */
#[Fillable(['tipo', 'chave', 'nsu', 'schema', 'emitente_cnpj', 'emitente_nome', 'data_emissao', 'valor_total', 'situacao', 'tem_xml_completo', 'manifestacao_status', 'xml_path', 'resumo_xml_path'])]
class DfeDocument extends Model
{
    protected function casts(): array
    {
        return [
            'tipo' => DocumentoTipo::class,
            'schema' => DfeSchema::class,
            'situacao' => Situacao::class,
            'manifestacao_status' => ManifestacaoStatus::class,
            'data_emissao' => 'datetime',
            'valor_total' => 'decimal:2',
            'tem_xml_completo' => 'boolean',
        ];
    }

    /** Margem de 1 hora sobre o prazo da SEFAZ, já que a data guardada é a de emissão e o prazo conta da autorização. */
    public function cienciaNoPrazo(): bool
    {
        return $this->data_emissao !== null
            && $this->data_emissao->greaterThan(now()->subDays(config('sefaz.ciencia_prazo_dias'))->addHour());
    }

    /**
     * Estado da nota em linguagem simples para a caixa de entrada.
     *
     * @return 'pronto'|'aguardando'|'resumo'|'cancelada'
     */
    public function estado(): string
    {
        return match (true) {
            $this->situacao === Situacao::Cancelada => 'cancelada',
            $this->tem_xml_completo => 'pronto',
            in_array($this->manifestacao_status, [ManifestacaoStatus::CienciaPendente, ManifestacaoStatus::Ciencia, ManifestacaoStatus::ConfirmacaoPendente, ManifestacaoStatus::Confirmada], true) => 'aguardando',
            default => 'resumo',
        };
    }

    public function conclusivaNoPrazo(): bool
    {
        return $this->data_emissao !== null
            && $this->data_emissao->greaterThan(now()->subDays(config('sefaz.confirmacao_prazo_dias'))->addHour());
    }

    /**
     * Resumos que ainda podem receber a Confirmação da Operação em lote: não cancelados, sem manifestação
     * conclusiva ou pendente e dentro do prazo de 180 dias.
     *
     * @param  Builder<DfeDocument>  $query
     */
    public function scopeConfirmaveisEmLote(Builder $query): void
    {
        $query->where('tem_xml_completo', false)
            ->where('situacao', '!=', Situacao::Cancelada->value)
            ->where('data_emissao', '>', now()->subDays(config('sefaz.confirmacao_prazo_dias'))->addHour())
            ->where(fn (Builder $q) => $q->whereNull('manifestacao_status')
                ->orWhereIn('manifestacao_status', [ManifestacaoStatus::Ciencia->value, ManifestacaoStatus::Erro->value]));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<DfeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DfeEvent::class);
    }
}
