<?php

namespace App\Models;

use App\Enums\DfeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $dfe_document_id
 * @property string $chave
 * @property string|null $nsu
 * @property DfeSchema $schema
 * @property string $tp_evento
 * @property int $n_seq_evento
 * @property string|null $descricao
 * @property string|null $autor_cnpj
 * @property CarbonImmutable|null $data_evento
 * @property string|null $protocolo
 * @property string|null $cstat
 * @property string $direcao
 * @property string|null $xml_path
 */
#[Fillable(['dfe_document_id', 'chave', 'nsu', 'schema', 'tp_evento', 'n_seq_evento', 'descricao', 'autor_cnpj', 'data_evento', 'protocolo', 'cstat', 'direcao', 'xml_path'])]
class DfeEvent extends Model
{
    protected function casts(): array
    {
        return [
            'schema' => DfeSchema::class,
            'data_evento' => 'datetime',
            'n_seq_evento' => 'integer',
        ];
    }

    /** @return BelongsTo<DfeDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }
}
