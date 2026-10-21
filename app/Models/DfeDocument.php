<?php

namespace App\Models;

use App\Enums\DfeSchema;
use App\Enums\DocumentoTipo;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
