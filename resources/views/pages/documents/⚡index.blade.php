<?php

use App\Enums\AuditAcao;
use App\Enums\ExportFormato;
use App\Enums\ExportStatus;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use App\Jobs\BuildCsvReport;
use App\Jobs\BuildXmlExport;
use App\Jobs\SendConfirmacaoOperacao;
use App\Models\DfeDocument;
use App\Models\SyncState;
use App\Queries\DocumentFilter;
use App\Services\Sefaz\SyncProgress;
use App\Support\Audit;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notas recebidas')] class extends Component {
    use WithPagination;

    /** Aba da caixa de entrada: '' (todas), 'sem_xml' ou 'canceladas'. */
    #[Url] public string $aba = '';
    #[Url] public string $q = '';
    #[Url] public string $tipo = '';
    #[Url] public string $company_id = '';
    #[Url] public string $de = '';
    #[Url] public string $ate = '';
    #[Url] public string $valor_min = '';
    #[Url] public string $valor_max = '';
    #[Url] public string $manifestacao = '';

    /** @var list<int> */
    public array $selecionados = [];

    /** Teto por envio: cada Confirmação é um evento assinado e enviado individualmente à SEFAZ. */
    private const LOTE_MAXIMO = 500;

    /** Filtros guardados no painel "Filtros"; a busca e as abas ficam sempre à vista. */
    private const FILTROS_EXTRAS = ['tipo', 'company_id', 'de', 'ate', 'valor_min', 'valor_max', 'manifestacao'];

    public function updated(string $property): void
    {
        if (in_array($property, ['aba', 'tipo'])) {
            $this->selecionados = [];
        }

        if ($property !== 'selecionados' && ! str_starts_with($property, 'selecionados.')) {
            $this->resetPage();
        }
    }

    public function limparFiltros(): void
    {
        $this->reset(self::FILTROS_EXTRAS);
        $this->resetPage();
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return [
            ...$this->only(self::FILTROS_EXTRAS),
            'busca' => $this->q,
            'xml' => $this->aba === 'sem_xml' ? 'resumo' : '',
            'situacao' => match ($this->aba) {
                'canceladas' => Situacao::Cancelada->value,
                // Nota cancelada não tem XML a liberar: fica só na aba própria.
                'sem_xml' => Situacao::Autorizada->value,
                default => '',
            },
        ];
    }

    #[Computed]
    public function filtrosAtivos(): int
    {
        return count(array_filter($this->only(self::FILTROS_EXTRAS), fn ($v) => $v !== ''));
    }

    /** @return array{sem_xml: int, canceladas: int} */
    #[Computed]
    public function contagens(): array
    {
        $base = fn () => DocumentFilter::apply(Auth::user()->currentOrganization, [...$this->only(self::FILTROS_EXTRAS), 'busca' => $this->q]);

        return [
            'sem_xml' => $base()->where('tem_xml_completo', false)->where('situacao', Situacao::Autorizada)->count(),
            'canceladas' => $base()->where('situacao', Situacao::Cancelada)->count(),
        ];
    }

    /** Quando o sistema falou com a SEFAZ por último e quando fala de novo (todas as empresas). */
    #[Computed]
    public function sincronizacao(): array
    {
        $states = SyncState::whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'));

        return [
            'ultima' => (clone $states)->max('last_checked_at'),
            'proxima' => (clone $states)->whereNotNull('next_check_at')->min('next_check_at'),
        ];
    }

    /** Empresas bloqueadas por consumo indevido (656) com next_check_at no futuro. */
    #[Computed]
    public function bloqueios(): array
    {
        return SyncState::query()
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->where('last_cstat', '656')
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '>', now())
            ->with('company:id,razao_social')
            ->get()
            ->map(fn (SyncState $s) => [
                'empresa' => $s->company->razao_social,
                'tipo' => $s->tipo->label(),
                'libera_em' => $s->next_check_at->timezone('America/Sao_Paulo')->format('H:i'),
            ])
            ->all();
    }

    public function sincronizarTodas(): void
    {
        $cooldown = app(\App\Services\Sefaz\CnpjCooldown::class);
        $companies = Auth::user()->currentOrganization->companies()->where('ativo', true)->get();
        $enfileiradas = 0;

        foreach ($companies as $company) {
            if ($company->buscaPeloContador()) {
                if (! \App\Services\SefSc\SefScDistribuicaoService::impedimento($company) && ! $company->sefScState()->next_check_at?->isFuture()) {
                    SyncProgress::queued($company->id, __('Busca SEF-SC enfileirada…'));
                    \App\Jobs\SyncCompanySefSc::dispatch($company);
                    $enfileiradas++;
                }

                continue;
            }

            $cert = $company->activeCertificate;
            if (! $cert || $cert->isExpired()) {
                continue;
            }

            foreach (\App\Enums\DocumentoTipo::cases() as $tipo) {
                $state = $company->syncState($tipo);
                $until = collect([$state->next_check_at, $cooldown->until($company, $tipo)])
                    ->filter(fn (?\Carbon\CarbonImmutable $d): bool => (bool) $d?->isFuture())->max();

                if (! $until) {
                    SyncProgress::queued($company->id, __('Sincronização enfileirada…'));
                    \App\Jobs\SyncCompanyDfe::dispatch($company, $tipo);
                    $enfileiradas++;
                }
            }
        }

        unset($this->sincronizacao);

        $enfileiradas > 0
            ? Flux::toast(variant: 'success', text: __(':n sincronizações enfileiradas.', ['n' => $enfileiradas]))
            : Flux::toast(variant: 'warning', text: __('Nenhuma empresa pôde ser sincronizada agora. Verifique os certificados ou aguarde o intervalo da SEFAZ.'));
    }

    /** Status agregado de sync de todas as empresas da organização. */
    #[Computed]
    public function syncStatuses(): array
    {
        $companies = Auth::user()->currentOrganization->companies()->where('ativo', true)->pluck('id', 'razao_social');
        $active = [];

        foreach ($companies as $nome => $id) {
            $progress = SyncProgress::get($id);

            if ($progress) {
                $active[] = ['nome' => $nome, ...$progress];
            }
        }

        return $active;
    }

    #[Computed]
    public function alertas()
    {
        return Auth::user()->unreadNotifications()
            ->where('data->organization_id', Auth::user()->current_organization_id)
            ->latest()->limit(5)->get();
    }

    public function dispensarAlerta(string $id): void
    {
        Auth::user()->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);
        unset($this->alertas);
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization->companies()->orderBy('razao_social')->get(['id', 'razao_social', 'cnpj']);
    }

    #[Computed]
    public function documents()
    {
        return DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())
            ->with('company:id,razao_social,nome_fantasia,cnpj')
            ->orderByDesc('data_emissao')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** Data de emissão no fuso de Brasília, para agrupar a lista por dia. */
    private const DIA_SQL = "date(data_emissao at time zone 'UTC' at time zone 'America/Sao_Paulo')";

    /**
     * Quantidade e soma de cada dia que aparece na página, contando todas as notas do dia (não só as desta página).
     *
     * @return array<string, array{n: int, total: float}>
     */
    #[Computed]
    public function resumoDias(): array
    {
        $dias = $this->documents->getCollection()
            ->map(fn (DfeDocument $d) => $d->data_emissao?->timezone(Fmt::TIMEZONE)->toDateString())
            ->filter()->unique()->values()->all();

        if ($dias === []) {
            return [];
        }

        return DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())
            ->toBase()
            ->selectRaw(self::DIA_SQL.' as dia, count(*) as n, coalesce(sum(valor_total), 0) as total')
            ->whereIn(DB::raw(self::DIA_SQL), $dias)
            ->groupBy('dia')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->dia => ['n' => (int) $r->n, 'total' => (float) $r->total]])
            ->all();
    }

    #[Computed]
    public function podeManifestar(): bool
    {
        return (bool) Auth::user()->roleIn(Auth::user()->current_organization_id)?->canManage();
    }

    /** @return list<int> IDs da página atual que aceitam a Confirmação em lote. */
    #[Computed]
    public function confirmaveisNaPagina(): array
    {
        if (! $this->podeManifestar) {
            return [];
        }

        return DfeDocument::query()->confirmaveisEmLote()
            ->whereIn('id', $this->documents->pluck('id'))
            ->pluck('id')->all();
    }

    #[Computed]
    public function totalConfirmaveis(): int
    {
        return $this->podeManifestar ? DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())->confirmaveisEmLote()->count() : 0;
    }

    public function selecionarFiltrados(): void
    {
        $this->selecionados = DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())
            ->confirmaveisEmLote()
            ->orderByDesc('data_emissao')
            ->limit(self::LOTE_MAXIMO)
            ->pluck('id')->all();
    }

    public function limparSelecao(): void
    {
        $this->selecionados = [];
    }

    public function confirmarLote(): void
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $this->selecionados))), 0, self::LOTE_MAXIMO);

        // Revalida tudo no servidor: a seleção vem do navegador.
        $documents = DocumentFilter::apply(Auth::user()->currentOrganization, [])
            ->confirmaveisEmLote()
            ->whereIn('id', $ids)
            ->with('company')
            ->get()
            ->filter(fn (DfeDocument $doc) => Gate::allows('manifest', $doc))
            ->values();

        foreach ($documents as $i => $doc) {
            $doc->update(['manifestacao_status' => ManifestacaoStatus::ConfirmacaoPendente]);
            SendConfirmacaoOperacao::dispatch($doc)->delay(now()->addSeconds($i * 2));
            Audit::document(AuditAcao::ConfirmacaoLote, $doc);
        }

        $this->selecionados = [];
        unset($this->documents, $this->confirmaveisNaPagina, $this->totalConfirmaveis);
        Flux::modal('confirmar-lote')->close();

        $documents->isEmpty()
            ? Flux::toast(variant: 'warning', text: __('Nenhuma das notas selecionadas pode receber a Confirmação.'))
            : Flux::toast(variant: 'success', text: __(':n Confirmações enviadas para a fila. O XML completo chega em seguida.', ['n' => $documents->count()]));
    }

    public function exportar(string $formato = 'zip'): void
    {
        $formato = ExportFormato::tryFrom($formato) ?? ExportFormato::Zip;
        $key = 'export:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            Flux::toast(variant: 'warning', text: __('Muitas exportações seguidas. Aguarde alguns minutos.'));

            return;
        }

        RateLimiter::hit($key, 300);

        $export = Auth::user()->currentOrganization->exports()->create([
            'user_id' => Auth::id(),
            'filters' => $this->filters(),
            'formato' => $formato,
            'status' => ExportStatus::Pending,
        ]);

        $formato === ExportFormato::Csv ? BuildCsvReport::dispatch($export) : BuildXmlExport::dispatch($export);
        Audit::record(AuditAcao::ExportacaoPedida, $export->organization_id, descricao: $formato->label(), dados: ['export_id' => $export->id, 'filtros' => array_filter($export->filters)]);

        $this->redirectRoute('exports.index', navigate: true);
    }
}; ?>
@php
    $syncs = $this->syncStatuses;
    $anySyncing = collect($syncs)->contains(fn ($s) => in_array($s['status'] ?? '', ['queued', 'running']));
    $anyRecent = ! empty($syncs);
@endphp
<section class="mx-auto w-full max-w-5xl space-y-6" @if($anySyncing) wire:poll.2s @elseif($anyRecent) wire:poll.5s @else wire:poll.30s @endif>
    {{-- Cabeçalho: o que é a tela, quando o sistema buscou notas e a ação principal (baixar). --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('Notas recebidas') }}</flux:heading>
            <flux:text>
                @if ($this->sincronizacao['ultima'])
                    {{ __('O sistema busca notas novas na SEFAZ sozinho. Última busca: :ultima.', ['ultima' => Fmt::relativo($this->sincronizacao['ultima'])]) }}
                @else
                    {{ __('Cadastre uma empresa com certificado para começar a receber notas.') }}
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="primary" icon="arrow-path" wire:click="sincronizarTodas">
                {{ __('Sincronizar') }}
            </flux:button>
            <flux:button icon="table-cells" wire:click="exportar('csv')" wire:confirm="{{ __('Gerar uma planilha com as :n notas desta lista?', ['n' => $this->documents->total()]) }}">
                {{ __('Baixar planilha') }}
            </flux:button>
            <flux:button icon="arrow-down-tray" wire:click="exportar('zip')" wire:confirm="{{ __('Gerar um arquivo ZIP com os XMLs das :n notas desta lista?', ['n' => $this->documents->total()]) }}">
                {{ __('Baixar XMLs') }}
            </flux:button>
        </div>
    </div>

    @foreach ($this->alertas as $alerta)
        <flux:callout :variant="$alerta->data['level'] ?? 'warning'" icon="bell-alert" wire:key="alerta-{{ $alerta->id }}">
            <flux:callout.heading>{{ $alerta->data['message'] }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" :href="$alerta->data['url']" wire:navigate>{{ __('Ver') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dispensarAlerta('{{ $alerta->id }}')">{{ __('Dispensar') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endforeach

    @if (! empty($this->bloqueios))
        <flux:callout icon="shield-exclamation" color="amber">
            <flux:callout.heading>{{ __('SEFAZ limitou consultas (consumo indevido)') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Isso é normal na primeira sincronização de uma empresa. O sistema tentará novamente automaticamente quando a SEFAZ liberar.') }}
                <ul class="mt-2 space-y-0.5">
                    @foreach ($this->bloqueios as $b)
                        <li>{{ $b['empresa'] }} ({{ $b['tipo'] }}) — {{ __('libera às :hora', ['hora' => $b['libera_em']]) }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (! empty($syncs))
        <div class="space-y-2">
            @foreach ($syncs as $sync)
                @php
                    $st = $sync['status'] ?? 'queued';
                    $isActive = in_array($st, ['queued', 'running']);
                    $isDone = $st === 'done';
                    $outcome = $sync['outcome'] ?? null;
                    $page = $sync['page'] ?? 0;
                    $docs = $sync['docs'] ?? 0;
                    $ultNsu = $sync['ult_nsu'] ?? null;
                    $maxNsu = $sync['max_nsu'] ?? null;
                    $pct = ($ultNsu && $maxNsu && (int) $maxNsu > 0) ? min(100, (int) round((int) $ultNsu / (int) $maxNsu * 100)) : null;
                @endphp

                @if ($isActive)
                    <div class="flex items-center gap-3 rounded border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm dark:border-blue-900 dark:bg-blue-950/40">
                        <svg class="size-4 shrink-0 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <div class="min-w-0 flex-1">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $sync['nome'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">
                                @if ($st === 'queued')
                                    — {{ __('na fila…') }}
                                @elseif ($page > 0)
                                    — {{ __('página :n', ['n' => $page]) }}
                                @else
                                    — {{ __('conectando…') }}
                                @endif
                            </span>
                        </div>
                        @if ($pct !== null)
                            <div class="w-24 rounded-full bg-blue-200 dark:bg-blue-900 h-1.5">
                                <div class="h-1.5 rounded-full bg-blue-500 transition-all duration-500" style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400 text-xs">{{ $pct }}%</span>
                        @endif
                        @if ($docs > 0)
                            <flux:badge color="blue" size="sm">{{ trans_choice(':n doc|:n docs', $docs, ['n' => $docs]) }}</flux:badge>
                        @endif
                    </div>
                @elseif ($isDone)
                    @php
                        $doneColor = match($outcome) { 'up_to_date' => 'green', 'blocked', 'failed' => 'red', default => 'zinc' };
                        $doneBorder = match($outcome) { 'up_to_date' => 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40', 'blocked', 'failed' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', default => 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' };
                        $doneLabel = match($outcome) { 'up_to_date' => __('em dia'), 'blocked' => __('bloqueado'), 'failed' => __('falhou'), 'not_due' => __('aguardando'), default => __('concluído') };
                    @endphp
                    <div class="flex items-center gap-3 rounded border {{ $doneBorder }} px-4 py-2.5 text-sm">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $sync['nome'] }}</span>
                        <flux:badge :color="$doneColor" size="sm">{{ $doneLabel }}</flux:badge>
                        @if ($docs > 0)
                            <span class="text-zinc-500 dark:text-zinc-400 text-xs tabular-nums">{{ trans_choice(':n documento|:n documentos', $docs, ['n' => $docs]) }}</span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Busca única + filtro de empresa + filtros extras numa gaveta. --}}
    <div class="flex gap-2">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.live.debounce.400ms="q" icon="magnifying-glass" :placeholder="__('Fornecedor, transportadora, CNPJ ou chave')" :aria-label="__('Buscar notas')" clearable />
        </div>
        @if ($this->companies->count() > 1)
            <flux:select wire:model.live="company_id" class="w-48 shrink-0" :aria-label="__('Filtrar por empresa')">
                <flux:select.option value="">{{ __('Todas as empresas') }}</flux:select.option>
                @foreach ($this->companies as $c)
                    <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:modal.trigger name="filtros">
            <flux:button icon="adjustments-horizontal">
                <span class="max-sm:sr-only">{{ __('Filtros') }}</span>
                @if ($this->filtrosAtivos)
                    <flux:badge size="sm" color="zinc" class="ms-1">{{ $this->filtrosAtivos }}</flux:badge>
                @endif
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:radio.group wire:model.live="tipo" variant="segmented" :aria-label="__('Tipo de documento')">
            <flux:radio value="" :label="__('Todos')" class="max-sm:px-2 max-sm:text-xs" />
            <flux:radio value="nfe" :label="__('NF-e')" class="max-sm:px-2 max-sm:text-xs" />
            <flux:radio value="cte" :label="__('CT-e')" class="max-sm:px-2 max-sm:text-xs" />
        </flux:radio.group>

        <flux:radio.group wire:model.live="aba" variant="segmented" :aria-label="__('Situação')">
            <flux:radio value="" :label="__('Todas')" class="max-sm:px-2 max-sm:text-xs" />
            <flux:radio value="sem_xml" :label="__('Sem XML (:n)', ['n' => number_format($this->contagens['sem_xml'], 0, ',', '.')])" class="max-sm:px-2 max-sm:text-xs" />
            <flux:radio value="canceladas" :label="__('Canceladas (:n)', ['n' => number_format($this->contagens['canceladas'], 0, ',', '.')])" class="max-sm:px-2 max-sm:text-xs" />
        </flux:radio.group>
    </div>

    @if ($aba === 'sem_xml')
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.text>
                {{ __('Destas notas a SEFAZ entregou só um resumo. Notas com até 10 dias recebem o XML sozinhas; para as mais antigas (até 180 dias), marque as que a empresa reconhece e clique em "Confirmar operação".') }}
            </flux:callout.text>
        </flux:callout>

        @if ($this->podeManifestar && $this->totalConfirmaveis > 0)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text>
                    {{ trans_choice(':count nota marcada|:count notas marcadas', count($selecionados), ['count' => count($selecionados)]) }}
                </flux:text>
                <div class="flex flex-wrap gap-2">
                    @if ($selecionados)
                        <flux:button size="sm" variant="ghost" wire:click="limparSelecao">{{ __('Desmarcar') }}</flux:button>
                        <flux:modal.trigger name="confirmar-lote">
                            <flux:button size="sm" variant="primary" icon="check-badge">{{ __('Confirmar operação') }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button size="sm" variant="ghost" wire:click="selecionarFiltrados">{{ __('Marcar todas (:n)', ['n' => min($this->totalConfirmaveis, 500)]) }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- Lista em grade densa, agrupada por dia de emissão (com quantidade e total do dia), no estilo de painéis de dados. --}}
    @php
        $grade = 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,1fr)_10rem_8rem_8.5rem_5.5rem]';
        $estados = [
            'pronto' => ['XML pronto', 'bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
            'aguardando' => ['XML a caminho', 'bg-sky-500', 'text-sky-700 dark:text-sky-400'],
            'resumo' => ['Só resumo', 'bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'],
            'cancelada' => ['Cancelada', 'bg-red-500', 'text-red-700 dark:text-red-400'],
        ];
    @endphp

    <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.class="opacity-60" wire:target="q,aba,company_id,de,ate,valor_min,valor_max,manifestacao,gotoPage,nextPage,previousPage">
        <div class="{{ $grade }} border-b border-zinc-200 px-4 py-2 text-xs font-medium tracking-wide text-zinc-500 uppercase max-md:hidden dark:border-zinc-700 dark:text-zinc-400" aria-hidden="true">
            <span>{{ __('Fornecedor') }}</span>
            <span>{{ __('CNPJ') }}</span>
            <span class="text-end">{{ __('Valor') }}</span>
            <span>{{ __('Estado') }}</span>
            <span class="text-end">{{ __('Arquivos') }}</span>
        </div>

        @forelse ($this->documents->getCollection()->groupBy(fn ($d) => $d->data_emissao?->timezone(Fmt::TIMEZONE)->toDateString() ?? '') as $dia => $docs)
            @php
                $resumo = $this->resumoDias[$dia] ?? null;
                $data = $dia ? Carbon\CarbonImmutable::parse($dia, Fmt::TIMEZONE) : null;
                $rotulo = match (true) {
                    $data === null => __('Sem data'),
                    $data->isToday() => __('Hoje'),
                    $data->isYesterday() => __('Ontem'),
                    default => ucfirst($data->locale('pt_BR')->translatedFormat('l, d/m')),
                };
            @endphp
            <section wire:key="dia-{{ $dia }}" aria-label="{{ $rotulo }}">
                <div class="sticky top-0 z-10 flex items-baseline justify-between gap-4 border-b border-zinc-200 bg-zinc-50 px-4 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                    <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $rotulo }}</span>
                    @if ($resumo)
                        <span class="tabular-nums text-zinc-500 dark:text-zinc-400">
                            {{ trans_choice(':n nota|:n notas', $resumo['n'], ['n' => $resumo['n']]) }} · {{ Fmt::money($resumo['total']) }}
                        </span>
                    @endif
                </div>

                <ul role="list" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($docs as $doc)
                        @php
                            [$estadoTexto, $estadoPonto, $estadoCor] = $estados[$doc->estado()];
                            $marcavel = $aba === 'sem_xml' && in_array($doc->id, $this->confirmaveisNaPagina, true);
                        @endphp
                        <li wire:key="doc-{{ $doc->id }}" class="group relative {{ $grade }} px-4 py-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($marcavel)
                                    <label class="relative z-10 -my-3 -ms-3 inline-flex size-11 shrink-0 cursor-pointer items-center justify-center">
                                        <input type="checkbox" wire:model.live="selecionados" value="{{ $doc->id }}" aria-label="{{ __('Marcar nota de :emitente', ['emitente' => $doc->emitente_nome ?? $doc->chave]) }}" class="size-4 cursor-pointer accent-zinc-800 dark:accent-white">
                                    </label>
                                @endif
                                <div class="min-w-0">
                                    {{-- O link cobre a linha toda: clicar em qualquer ponto abre a nota. --}}
                                    <a href="{{ route('documents.show', $doc) }}" wire:navigate class="block truncate font-medium text-zinc-900 after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-zinc-400 focus-visible:after:ring-inset dark:text-white">
                                        @if ($doc->tipo === App\Enums\DocumentoTipo::Cte)
                                            <span class="me-1.5 inline-block border border-zinc-300 px-1 py-px align-[1px] text-[10px] font-semibold tracking-wide text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">CT-e</span>
                                        @endif
                                        @if ($doc->emitidaPelaEmpresa())
                                            <span class="me-1.5 inline-block border border-violet-300 px-1 py-px align-[1px] text-[10px] font-semibold tracking-wide text-violet-700 dark:border-violet-700 dark:text-violet-300" title="{{ __('Nota de saída: emitida pela própria empresa') }}">{{ __('Emitida') }}</span>
                                        @endif
                                        {{ $doc->emitente_nome ?? __('Fornecedor não informado') }}
                                    </a>
                                    @if ($this->companies->count() > 1)
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ __('para :empresa', ['empresa' => $doc->company->nome_fantasia ?: $doc->company->razao_social]) }}</p>
                                    @endif
                                    <p class="mt-0.5 flex items-center gap-1.5 text-xs md:hidden {{ $estadoCor }}">
                                        <span class="size-1.5 shrink-0 rounded-full {{ $estadoPonto }}" aria-hidden="true"></span>{{ __($estadoTexto) }}
                                    </p>
                                </div>
                            </div>

                            <span class="text-sm tabular-nums text-zinc-500 max-md:hidden dark:text-zinc-400">{{ Fmt::cnpj($doc->emitente_cnpj) }}</span>

                            <span class="text-end text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ Fmt::money($doc->valor_total) }}</span>

                            <span class="flex items-center gap-2 text-sm max-md:hidden {{ $estadoCor }}">
                                <span class="size-2 shrink-0 rounded-full {{ $estadoPonto }}" aria-hidden="true"></span>{{ __($estadoTexto) }}
                            </span>

                            <div class="relative z-10 flex justify-end gap-0.5 max-md:hidden">
                                @if ($doc->tem_xml_completo)
                                    <flux:tooltip :content="__('Baixar XML')">
                                        <flux:button size="sm" variant="subtle" square icon="code-bracket" :href="route('documents.xml', $doc)" :aria-label="__('Baixar XML de :emitente', ['emitente' => $doc->emitente_nome])" />
                                    </flux:tooltip>
                                    <flux:tooltip :content="__('Abrir DANFE')">
                                        <flux:button size="sm" variant="subtle" square icon="document-text" :href="route('documents.danfe', $doc)" target="_blank" :aria-label="__('Abrir DANFE de :emitente', ['emitente' => $doc->emitente_nome])" />
                                    </flux:tooltip>
                                @else
                                    <flux:text size="sm" class="pe-2 text-zinc-400 dark:text-zinc-500">—</flux:text>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="px-4 py-12 text-center">
                <flux:heading>
                    @if ($q !== '' || $this->filtrosAtivos)
                        {{ __('Nenhuma nota encontrada com essa busca.') }}
                    @elseif ($aba === 'sem_xml')
                        {{ __('Tudo certo: todas as notas já têm XML.') }}
                    @elseif ($aba === 'canceladas')
                        {{ __('Nenhuma nota cancelada.') }}
                    @else
                        {{ __('Nenhuma nota recebida ainda.') }}
                    @endif
                </flux:heading>
                @if ($q !== '' || $this->filtrosAtivos)
                    <flux:button size="sm" variant="ghost" class="mt-2" wire:click="$set('q', ''); limparFiltros()">{{ __('Limpar busca e filtros') }}</flux:button>
                @endif
            </div>
        @endforelse
    </div>

    <flux:pagination :paginator="$this->documents" />

    <flux:modal name="filtros" variant="flyout" class="w-full max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Filtros') }}</flux:heading>

            <flux:select wire:model.live="tipo" :label="__('Tipo de documento')">
                <flux:select.option value="">{{ __('NF-e e CT-e') }}</flux:select.option>
                @foreach (App\Enums\DocumentoTipo::cases() as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->companies->count() > 1)
                <flux:select wire:model.live="company_id" :label="__('Empresa')">
                    <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
                    @foreach ($this->companies as $c)
                        <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="date" wire:model.live="de" :label="__('Emitida de')" />
                <flux:input type="date" wire:model.live="ate" :label="__('até')" />
                <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="valor_min" :label="__('Valor de (R$)')" />
                <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="valor_max" :label="__('até (R$)')" />
            </div>

            <flux:select wire:model.live="manifestacao" :label="__('Manifestação')">
                <flux:select.option value="">{{ __('Qualquer') }}</flux:select.option>
                <flux:select.option value="nenhuma">{{ __('Sem manifestação') }}</flux:select.option>
                @foreach (ManifestacaoStatus::cases() as $m)
                    <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-between gap-2">
                <flux:button variant="ghost" wire:click="limparFiltros">{{ __('Limpar filtros') }}</flux:button>
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Ver :n notas', ['n' => number_format($this->documents->total(), 0, ',', '.')]) }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirmar-lote" class="md:w-[32rem]">
        <form wire:submit="confirmarLote" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">{{ __('Confirmar operação de :count notas', ['count' => count($selecionados)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('A Confirmação declara à SEFAZ que a compra aconteceu e a mercadoria foi recebida. Cada evento é assinado com o certificado da empresa e não pode ser desfeito.') }}</flux:text>
                <flux:text class="mt-2">{{ __('Envie apenas notas que a empresa reconhece. Para notas desconhecidas, abra a nota e use "Desconhecimento".') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Confirmar e enviar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
