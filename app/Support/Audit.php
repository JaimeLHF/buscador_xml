<?php

namespace App\Support;

use App\Enums\AuditAcao;
use App\Models\AuditLog;
use App\Models\DfeDocument;
use Illuminate\Support\Facades\Auth;

/** Grava a trilha de auditoria com o usuário e o IP da requisição atual (nulos em jobs). */
final class Audit
{
    /** @param  array<string, mixed>  $dados */
    public static function record(AuditAcao $acao, int $organizationId, ?DfeDocument $document = null, ?string $descricao = null, array $dados = []): AuditLog
    {
        return AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => Auth::id(),
            'dfe_document_id' => $document?->id,
            'acao' => $acao,
            'descricao' => $descricao,
            // Chamadas pela API não têm usuário: o nome do token identifica quem foi.
            'dados' => [...$dados, ...array_filter(['token' => request()->attributes->get('api_token')?->name])] ?: null,
            'ip' => request()->ip(),
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public static function document(AuditAcao $acao, DfeDocument $document, ?string $descricao = null, array $dados = []): AuditLog
    {
        return self::record($acao, $document->company->organization_id, $document, $descricao, $dados);
    }
}
