<?php

namespace App\Models;

use App\Enums\AuditAcao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de quem fez o quê com os documentos da organização. Só inserção: nada aqui é editado.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property int|null $dfe_document_id
 * @property AuditAcao $acao
 * @property string|null $descricao
 * @property array<string, mixed>|null $dados
 * @property string|null $ip
 * @property CarbonImmutable $created_at
 */
#[Fillable(['organization_id', 'user_id', 'dfe_document_id', 'acao', 'descricao', 'dados', 'ip'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'acao' => AuditAcao::class,
            'dados' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DfeDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }
}
