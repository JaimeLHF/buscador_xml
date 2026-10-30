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
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de leitura para ERPs: lista as notas da empresa da conexão (ou da organização, nas chaves antigas) (com cursor, para sincronizar só o que mudou)
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
            'direcao' => ['nullable', 'in:entrada,saida'],
            'empresa_cnpj' => self::cnpjRules(),
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'busca' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Janela de assentamento: a distribuição grava uma página inteira numa transação, com o updated_at de cada
        // linha marcado antes do commit. Uma linha confirmada depois desta leitura pode ter updated_at anterior ao
        // último item entregue, e o cursor passaria por cima dela para sempre. Só entregando linhas mais velhas que
        // a janela (maior que qualquer transação), tudo o que ainda pode aparecer "no passado" fica para a próxima rodada.
        $assentadoAte = now()->subSeconds((int) config('api.assentamento_segundos'));

        $page = $this->query($request)
            ->with('company:id,cnpj')
            ->where('updated_at', '<', $assentadoAte)
            ->when($request->date('alterados_desde'), fn (Builder $q, DateTimeInterface $d) => $q->where('updated_at', '>=', CarbonImmutable::instance($d)->utc()))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->cursorPaginate($request->integer('por_pagina', 50));

        $ultimo = $page->getCollection()->last();
        $request->attributes->set('api_quantidade', $page->count());

        return response()->json([
            'data' => $page->getCollection()->map(fn (DfeDocument $d) => $this->resource($d))->all(),
            'proximo_cursor' => $page->nextCursor()?->encode(),
            // Onde a próxima rodada continua, inclusive depois da última página. Página vazia devolve o cursor recebido.
            'cursor_retomada' => $ultimo !== null ? $page->getCursorForItem($ultimo)->encode() : $request->query('cursor'),
        ]);
    }

    public function show(Request $request, string $chave): JsonResponse
    {
        $document = $this->find($request, $chave);
        $request->attributes->set('api_quantidade', 1);

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
        $request->attributes->set('api_quantidade', 1);

        Audit::document(AuditAcao::XmlBaixado, $document, 'via API');

        return response($content, 200, ['Content-Type' => 'application/xml']);
    }

    /** @return Builder<DfeDocument> */
    private function query(Request $request): Builder
    {
        $token = $this->token($request);

        // Conexão de uma empresa: só as notas dela, mesmo que peça o CNPJ de outra (que dá lista vazia).
        return DocumentFilter::apply($token->organization, $request->only(['tipo', 'situacao', 'xml', 'direcao', 'empresa_cnpj', 'de', 'ate', 'busca']))
            ->when($token->company_id, fn (Builder $q, int $id) => $q->where('company_id', $id));
    }

    private function find(Request $request, string $chave): DfeDocument
    {
        abort_unless(preg_match('/^\d{44}$/', $chave) === 1, 404);

        // A mesma chave pode existir em duas empresas da organização: devolve a de XML completo primeiro.
        return $this->query($request)->where('chave', $chave)->with('company')->orderByDesc('tem_xml_completo')->firstOrFail();
    }

    /**
     * CNPJ só com os 14 dígitos e dígitos verificadores corretos.
     *
     * @return list<mixed>
     */
    public static function cnpjRules(bool $obrigatorio = false): array
    {
        return [$obrigatorio ? 'required' : 'nullable', 'string', 'regex:/^\d{14}$/', function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! Cnpj::isValid($value)) {
                $fail('CNPJ inválido.');
            }
        }];
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
            'direcao' => $d->emitidaPelaEmpresa() ? 'saida' : 'entrada',
            'data_emissao' => $d->data_emissao?->toIso8601String(),
            'valor_total' => $d->valor_total,
            'situacao' => $d->situacao->value,
            'manifestacao' => $d->manifestacao_status?->value,
            'xml_completo' => $d->tem_xml_completo,
            'xml_reconstruido' => $d->xml_reconstruido,
            'conferencia_sped' => $d->conferencia_sped?->value,
            'conferencia_sped_detalhe' => $d->conferencia_sped_detalhe,
            'atualizado_em' => $d->updated_at?->toIso8601String(),
        ];
    }
}
