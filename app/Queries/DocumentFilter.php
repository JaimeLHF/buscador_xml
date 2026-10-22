<?php

namespace App\Queries;

use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use App\Models\DfeDocument;
use App\Models\Organization;
use App\Support\Cnpj;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros da lista de documentos, reaproveitados na exportação ZIP. Sempre restrito a uma organização.
 *
 * @phpstan-type Filters array{company_id?: int|string|null, de?: string|null, ate?: string|null, emitente?: string|null, valor_min?: string|float|null, valor_max?: string|float|null, situacao?: string|null, xml?: string|null, manifestacao?: string|null, chave?: string|null, busca?: string|null}
 */
final class DocumentFilter
{
    /**
     * @param  Filters  $filters
     * @return Builder<DfeDocument>
     */
    public static function apply(Organization $organization, array $filters): Builder
    {
        $f = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $filters);

        return DfeDocument::query()
            ->whereIn('company_id', $organization->companies()->select('id'))
            ->when($f['company_id'] ?? null, fn (Builder $q, $id) => $q->where('company_id', (int) $id))
            ->when($f['de'] ?? null, fn (Builder $q, $d) => $q->where('data_emissao', '>=', CarbonImmutable::parse($d, Fmt::TIMEZONE)->startOfDay()->utc()))
            ->when($f['ate'] ?? null, fn (Builder $q, $d) => $q->where('data_emissao', '<=', CarbonImmutable::parse($d, Fmt::TIMEZONE)->endOfDay()->utc()))
            ->when($f['emitente'] ?? null, fn (Builder $q, string $term) => self::emitente($q, $term))
            ->when(is_numeric($f['valor_min'] ?? null) ? (float) $f['valor_min'] : null, fn (Builder $q, float $v) => $q->where('valor_total', '>=', $v))
            ->when(is_numeric($f['valor_max'] ?? null) ? (float) $f['valor_max'] : null, fn (Builder $q, float $v) => $q->where('valor_total', '<=', $v))
            ->when(Situacao::tryFrom((string) ($f['situacao'] ?? '')), fn (Builder $q, Situacao $s) => $q->where('situacao', $s))
            ->when(($f['xml'] ?? null) === 'completo', fn (Builder $q) => $q->where('tem_xml_completo', true))
            ->when(($f['xml'] ?? null) === 'resumo', fn (Builder $q) => $q->where('tem_xml_completo', false))
            ->when($f['manifestacao'] ?? null, fn (Builder $q, string $m) => $m === 'nenhuma'
                ? $q->whereNull('manifestacao_status')
                : $q->where('manifestacao_status', ManifestacaoStatus::tryFrom($m)->value ?? '__'))
            ->when($f['chave'] ?? null, fn (Builder $q, string $c) => $q->where('chave', 'like', preg_replace('/\D/', '', $c).'%'))
            // Busca única da caixa de entrada: só dígitos e 15 ou mais é trecho de chave de acesso; o resto, fornecedor (nome ou CNPJ).
            ->when($f['busca'] ?? null, fn (Builder $q, string $term) => preg_match('/^[\d\s.\-\/]+$/', $term) && strlen(preg_replace('/\D/', '', $term)) >= 15
                ? $q->where('chave', 'like', preg_replace('/\D/', '', $term).'%')
                : self::emitente($q, $term));
    }

    /** @param  Builder<DfeDocument>  $q */
    private static function emitente(Builder $q, string $term): void
    {
        $cnpj = Cnpj::normalize($term);

        $q->where(fn (Builder $q) => $q->where('emitente_nome', 'ilike', '%'.addcslashes($term, '%_').'%')
            ->when(preg_match('/^[0-9A-Z]{3,14}$/', $cnpj), fn (Builder $q) => $q->orWhere('emitente_cnpj', 'like', $cnpj.'%')));
    }
}
