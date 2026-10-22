<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAcao;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\DfeDocument;
use App\Models\DfeEvent;
use App\Queries\DocumentFilter;
use App\Services\Sefaz\XmlStorage;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de leitura para ERPs: lista as notas da organização do token (com cursor, para sincronizar só o que mudou)
 * e entrega o XML. Nunca escreve nem manifesta.
 */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'alterados_desde' => ['nullable', 'date'],
            'tipo' => ['nullable', 'in:nfe,cte'],
            'situacao' => ['nullable', 'in:autorizada,cancelada,denegada'],
            'xml' => ['nullable', 'in:completo,resumo'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'busca' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->query($request)
            ->with('company:id,cnpj')
            ->when($request->date('alterados_desde'), fn (Builder $q, DateTimeInterface $d) => $q->where('updated_at', '>=', CarbonImmutable::instance($d)->utc()))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->cursorPaginate($request->integer('por_pagina', 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DfeDocument $d) => $this->resource($d))->all(),
            'proximo_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, string $chave): JsonResponse
    {
        $document = $this->find($request, $chave);

        return response()->json(['data' => [
            ...$this->resource($document),
            'eventos' => $document->company->events()->where('chave', $document->chave)->orderBy('data_evento')->get()
                ->map(fn (DfeEvent $e) => [
                    'tipo' => $e->tp_evento,
                    'descricao' => $e->descricao,
                    'sequencia' => $e->n_seq_evento,
                    'data' => $e->data_evento?->toIso8601String(),
                    'protocolo' => $e->protocolo,
                    'direcao' => $e->direcao,
                ])->all(),
        ]]);
    }

    public function xml(Request $request, string $chave, XmlStorage $storage): Response
    {
        $document = $this->find($request, $chave);
        $path = $document->xml_path ?? $document->resumo_xml_path;
        $content = $path ? $storage->get($path) : null;
        abort_if($content === null, 404, 'XML não encontrado.');

        Audit::document(AuditAcao::XmlBaixado, $document, 'via API');

        return response($content, 200, ['Content-Type' => 'application/xml']);
    }

    /** @return Builder<DfeDocument> */
    private function query(Request $request): Builder
    {
        return DocumentFilter::apply($this->token($request)->organization, $request->only(['tipo', 'situacao', 'xml', 'de', 'ate', 'busca']));
    }

    private function find(Request $request, string $chave): DfeDocument
    {
        abort_unless(preg_match('/^\d{44}$/', $chave) === 1, 404);

        // A mesma chave pode existir em duas empresas da organização: devolve a de XML completo primeiro.
        return $this->query($request)->where('chave', $chave)->with('company')->orderByDesc('tem_xml_completo')->firstOrFail();
    }

    private function token(Request $request): ApiToken
    {
        return $request->attributes->get('api_token');
    }

    /** @return array<string, mixed> */
    private function resource(DfeDocument $d): array
    {
        return [
            'chave' => $d->chave,
            'tipo' => $d->tipo->value,
            'empresa_cnpj' => $d->company->cnpj,
            'emitente_cnpj' => $d->emitente_cnpj,
            'emitente_nome' => $d->emitente_nome,
            'data_emissao' => $d->data_emissao?->toIso8601String(),
            'valor_total' => $d->valor_total,
            'situacao' => $d->situacao->value,
            'manifestacao' => $d->manifestacao_status?->value,
            'xml_completo' => $d->tem_xml_completo,
            'atualizado_em' => $d->updated_at?->toIso8601String(),
        ];
    }
}
