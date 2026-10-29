<?php

use App\Enums\AuditAcao;
use App\Enums\ChaveHistoricaStatus;
use App\Enums\NaturezaCompra;
use App\Models\ChaveHistorica;
use App\Models\Company;
use App\Services\MeuDanfe\MeuDanfeClient;
use App\Services\MeuDanfe\SpedChaves;
use App\Support\Audit;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Chaves do SPED')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    /** Teto de uma consulta completa em lote (cada chave é cobrada). */
    public const LOTE_MAXIMO = 1000;

    public Company $company;

    /** @var list<Illuminate\Http\UploadedFile> */
    public array $speds = [];

    /** @var array{lidas: int, novas: int, com_xml: int}|null */
    public ?array $importacao = null;

    #[Url] public string $status = '';
    #[Url] public string $mes = '';
    #[Url] public string $q = '';
    #[Url] public string $natureza = '';

    /** @var list<int|string> */
    public array $selecionados = [];

    public function mount(Company $company): void
    {
        Gate::authorize('update', $company);

        $this->company = $company;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'mes', 'q', 'natureza'], true)) {
            $this->resetPage();
        }
    }

    /** Registra as chaves dos arquivos e mostra as que ainda não têm XML: nada é consultado sem alguém marcar. */
    public function importar(): void
    {
        Gate::authorize('update', $this->company);

        $this->validate(['speds' => 'required|array|max:24', 'speds.*' => 'file|mimes:txt|max:51200']);

        $total = ['lidas' => 0, 'novas' => 0, 'com_xml' => 0];

        foreach ($this->speds as $arquivo) {
            $r = SpedChaves::importar($this->company, (string) file_get_contents($arquivo->getRealPath()));
            $total = ['lidas' => $total['lidas'] + $r['lidas'], 'novas' => $total['novas'] + $r['novas'], 'com_xml' => $total['com_xml'] + $r['com_xml']];
        }

        $this->reset('speds', 'mes', 'q', 'selecionados');
        $this->importacao = $total;
        $this->status = ChaveHistoricaStatus::Importada->value;
        $this->resetPage();
    }

    /** @return Builder<ChaveHistorica> */
    private function filtradas(): Builder
    {
        $termo = preg_replace('/\D/', '', $this->q);

        return $this->company->chavesHistoricas()->getQuery()
            ->when(ChaveHistoricaStatus::tryFrom($this->status), fn (Builder $q, ChaveHistoricaStatus $s) => $q->where('status', $s))
            ->when(preg_match('/^\d{4}-\d{2}$/', $this->mes) === 1, fn (Builder $q) => $q->whereBetween('sped_data', [$this->mes.'-01', Carbon\CarbonImmutable::parse($this->mes.'-01')->endOfMonth()->toDateString()]))
            ->when(NaturezaCompra::tryFrom($this->natureza), fn (Builder $q, NaturezaCompra $n) => $q->where('natureza', $n))
            ->when($termo !== '', fn (Builder $q) => $q->where('chave', 'like', '%'.$termo.'%'));
    }

    #[Computed]
    public function chaves()
    {
        return $this->filtradas()->orderByRaw('sped_data desc nulls last')->orderBy('id')->paginate(50);
    }

    /** Nome do fornecedor a partir das notas que a empresa já recebeu dele (o SPED só traz a chave). */
    #[Computed]
    public function fornecedores(): array
    {
        $cnpjs = $this->chaves->getCollection()->map(fn (ChaveHistorica $c) => substr($c->chave, 6, 14))->unique()->values()->all();

        return $this->company->documents()->toBase()->whereIn('emitente_cnpj', $cnpjs)->whereNotNull('emitente_nome')
            ->groupBy('emitente_cnpj')->selectRaw('emitente_cnpj, max(emitente_nome) as nome')->pluck('nome', 'emitente_cnpj')->all();
    }

    /** @return array<string, int> */
    #[Computed]
    public function contagem(): array
    {
        return $this->company->chavesHistoricas()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** @return list<string> AAAA-MM dos meses que têm chaves */
    #[Computed]
    public function meses(): array
    {
        return $this->company->chavesHistoricas()->toBase()->whereNotNull('sped_data')
            ->selectRaw("distinct to_char(sped_data, 'YYYY-MM') as mes")->orderByDesc('mes')->pluck('mes')->all();
    }

    /** Naturezas presentes nas chaves da empresa, com a quantidade de cada uma. @return array<string, int> */
    #[Computed]
    public function naturezas(): array
    {
        return $this->company->chavesHistoricas()->toBase()->whereNotNull('natureza')->groupBy('natureza')
            ->selectRaw('natureza, count(*) as n')->orderByDesc('n')->pluck('n', 'natureza')->map(fn ($n) => (int) $n)->all();
    }

    #[Computed]
    public function selecionaveisFiltradas(): int
    {
        return $this->filtradas()->whereIn('status', self::statusSelecionaveis())->consultaveis()->count();
    }

    /** @return list<int> */
    #[Computed]
    public function selecionaveisNaPagina(): array
    {
        return $this->chaves->getCollection()->filter(fn (ChaveHistorica $c) => $c->consultavel())->pluck('id')->all();
    }

    /**
     * Quantidade e valor de cada dia da página, contando o dia inteiro dentro dos filtros (não só o que coube na página).
     *
     * @return array<string, array{n: int, total: string|null}>
     */
    #[Computed]
    public function resumoDias(): array
    {
        $dias = $this->chaves->getCollection()->map(fn (ChaveHistorica $c) => $c->sped_data?->toDateString())->filter()->unique()->values()->all();

        return $this->filtradas()->toBase()->whereIn('sped_data', $dias)->groupBy('sped_data')
            ->selectRaw('sped_data, count(*) as n, sum(sped_valor) as total')->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->sped_data, 0, 10) => ['n' => (int) $r->n, 'total' => $r->total]])->all();
    }

    /** Nota já gravada de cada chave encontrada na página, para abrir direto. @return array<string, int> */
    #[Computed]
    public function notas(): array
    {
        return $this->company->documents()->toBase()->where('tem_xml_completo', true)
            ->whereIn('chave', $this->chaves->getCollection()->pluck('chave')->all())->pluck('id', 'chave')->all();
    }

    /** Marca as selecionáveis da página; se já estavam todas marcadas, desmarca. */
    public function alternarPagina(): void
    {
        $pagina = $this->selecionaveisNaPagina;
        $marcadas = array_map('intval', $this->selecionados);

        $this->selecionados = array_diff($pagina, $marcadas) === []
            ? array_values(array_diff($marcadas, $pagina))
            : array_slice(array_values(array_unique([...$marcadas, ...$pagina])), 0, self::LOTE_MAXIMO);
    }

    public function marcarFiltradas(): void
    {
        $this->selecionados = $this->filtradas()->whereIn('status', self::statusSelecionaveis())->consultaveis()
            ->orderByRaw('sped_data desc nulls last')->orderBy('id')->limit(self::LOTE_MAXIMO)->pluck('id')->all();
    }

    public function desmarcar(): void
    {
        $this->selecionados = [];
    }

    public function consultar(): void
    {
        Gate::authorize('update', $this->company);

        if (! MeuDanfeClient::configurado()) {
            Flux::toast(variant: 'danger', text: __('A consulta completa de NF-e não está configurada.'));

            return;
        }

        // Revalida no servidor: a seleção vem do navegador e só conta o que é desta empresa e ainda pode ser consultado.
        $ids = array_slice(array_values(array_unique(array_map('intval', $this->selecionados))), 0, self::LOTE_MAXIMO);
        $n = SpedChaves::enfileirar($this->company, $ids);

        if ($n > 0) {
            Audit::record(AuditAcao::ConsultaNfeCompleta, $this->company->organization_id, descricao: $this->company->razao_social, dados: ['chaves' => $n]);
        }

        $this->selecionados = [];
        unset($this->chaves, $this->contagem, $this->selecionaveisFiltradas);
        Flux::modal('consultar-nfe')->close();

        $n === 0
            ? Flux::toast(variant: 'warning', text: __('Nenhuma das chaves marcadas pode ser consultada.'))
            : Flux::toast(variant: 'success', text: trans_choice(':n chave foi para a consulta completa.|:n chaves foram para a consulta completa.', $n, ['n' => $n]));
    }

    /** @return list<string> */
    private static function statusSelecionaveis(): array
    {
        return array_values(array_map(fn (ChaveHistoricaStatus $s) => $s->value, array_filter(ChaveHistoricaStatus::cases(), fn (ChaveHistoricaStatus $s) => $s->selecionavel())));
    }
}; ?>

@php
    $naFila = $this->contagem[ChaveHistoricaStatus::Pendente->value] ?? 0;
    $total = array_sum($this->contagem);
@endphp
<section class="w-full space-y-6" @if ($naFila > 0) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('companies.show', $company)" wire:navigate class="text-sm">← {{ $company->nome_fantasia ?: $company->razao_social }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-1">{{ __('Chaves do SPED') }}</flux:heading>
            <flux:text class="mt-1 max-w-2xl">{{ __('Envie os TXT do SPED EFD ICMS/IPI baixados do ReceitanetBX. As notas de entrada aparecem aqui; marque as que quer baixar completas e consulte em lote.') }}</flux:text>
        </div>
    </div>

    <flux:card class="space-y-4">
        <form wire:submit="importar" class="flex flex-wrap items-end gap-3">
            <flux:input type="file" wire:model="speds" multiple accept=".txt" :label="__('Arquivos SPED (.txt)')" />
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="speds,importar">{{ __('Enviar e ler chaves') }}</flux:button>
        </form>
        <flux:error name="speds" />
        <flux:error name="speds.*" />

        @if ($importacao)
            <flux:callout icon="document-check" color="green">
                <flux:callout.heading>{{ __(':l notas de entrada no SPED', ['l' => number_format($importacao['lidas'], 0, ',', '.')]) }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __(':x já tinham o XML · :n novas aguardando seleção. Elas estão listadas abaixo.', ['x' => number_format($importacao['com_xml'], 0, ',', '.'), 'n' => number_format($importacao['novas'], 0, ',', '.')]) }}
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:card>

    @if ($total > 0)
        @php
            // Ponto e texto por situação, no mesmo tom da lista de notas: cor só onde há informação.
            $tom = fn (ChaveHistoricaStatus $st) => match ($st->cor()) {
                // Aguardando seleção é o estado comum da lista: só o ponto leva cor, o texto fica neutro.
                'amber' => ['bg-amber-500', 'text-zinc-600 dark:text-zinc-300'],
                'blue' => ['bg-sky-500', 'text-sky-700 dark:text-sky-400'],
                'green' => ['bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
                'red' => ['bg-red-500', 'text-red-700 dark:text-red-400'],
                default => ['bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'],
            };
            $pagina = $this->selecionaveisNaPagina;
            $marcadas = array_map('intval', $selecionados);
            $paginaToda = $pagina !== [] && array_diff($pagina, $marcadas) === [];
            $grade = 'grid grid-cols-[2.75rem_minmax(0,1fr)_auto] items-center gap-x-3 md:grid-cols-[2.75rem_minmax(0,1fr)_6rem_10rem_9.5rem_11rem] md:gap-x-5';
        @endphp

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Filtrar por situação') }}">
                <flux:button size="sm" :variant="$status === '' ? 'primary' : 'ghost'" wire:click="$set('status', '')">
                    {{ __('Todas') }} <span class="tabular-nums opacity-70">{{ number_format($total, 0, ',', '.') }}</span>
                </flux:button>
                @foreach (ChaveHistoricaStatus::cases() as $st)
                    @if ($n = $this->contagem[$st->value] ?? 0)
                        <flux:button size="sm" :variant="$status === $st->value ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $st->value }}')" :aria-pressed="$status === $st->value ? 'true' : 'false'">
                            <span class="size-2 shrink-0 rounded-full {{ $tom($st)[0] }}" aria-hidden="true"></span>
                            {{ $st->label() }} <span class="tabular-nums opacity-70">{{ number_format($n, 0, ',', '.') }}</span>
                        </flux:button>
                    @endif
                @endforeach
            </div>

            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <div class="min-w-0 basis-full sm:w-60 sm:basis-auto">
                    <flux:input size="sm" wire:model.live.debounce.400ms="q" icon="magnifying-glass" :placeholder="__('Chave ou CNPJ')" clearable :aria-label="__('Buscar chave ou CNPJ do fornecedor')" />
                </div>
                @if ($this->naturezas)
                    <div class="w-48 shrink-0">
                        <flux:select size="sm" wire:model.live="natureza" :aria-label="__('Natureza da compra')">
                            <flux:select.option value="">{{ __('Todas as naturezas') }}</flux:select.option>
                            @foreach ($this->naturezas as $valor => $n)
                                <flux:select.option :value="$valor">{{ NaturezaCompra::from($valor)->label() }} ({{ number_format($n, 0, ',', '.') }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
                <div class="w-40 shrink-0">
                <flux:select size="sm" wire:model.live="mes" :aria-label="__('Mês do SPED')">
                    <flux:select.option value="">{{ __('Todos os meses') }}</flux:select.option>
                    @foreach ($this->meses as $m)
                        <flux:select.option :value="$m">{{ ucfirst(Carbon\CarbonImmutable::parse($m.'-01')->locale('pt_BR')->translatedFormat('M/Y')) }}</flux:select.option>
                    @endforeach
                </flux:select>
                </div>
            </div>
        </div>

        <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.class="opacity-60" wire:target="status,mes,q,natureza,gotoPage,nextPage,previousPage,importar">
            {{-- Barra de seleção: fica presa no topo enquanto há chaves marcadas. --}}
            @if ($this->selecionaveisFiltradas > 0 || $selecionados)
                <div @class([
                    'sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3 border-b px-4 py-2',
                    'border-zinc-800 bg-zinc-900 text-white dark:border-white/20 dark:bg-zinc-100 dark:text-zinc-900' => $selecionados,
                    'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60' => ! $selecionados,
                ])>
                    @if ($selecionados)
                        <p class="text-sm">
                            <span class="font-semibold tabular-nums">{{ trans_choice(':count chave marcada|:count chaves marcadas', count($selecionados), ['count' => number_format(count($selecionados), 0, ',', '.')]) }}</span>
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="desmarcar" class="rounded px-2.5 py-1 text-sm opacity-80 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2">{{ __('Desmarcar') }}</button>
                            <flux:modal.trigger name="consultar-nfe">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800">
                                    <flux:icon.magnifying-glass-circle variant="micro" />{{ __('Consultar NF-e completa') }}
                                </button>
                            </flux:modal.trigger>
                        </div>
                    @else
                        <flux:text size="sm">{{ __('Marque as chaves para consultar a NF-e completa. Clique em qualquer ponto da linha.') }}</flux:text>
                        <flux:button size="sm" variant="subtle" wire:click="marcarFiltradas">{{ __('Marcar todas as filtradas (:n)', ['n' => number_format(min($this->selecionaveisFiltradas, $this::LOTE_MAXIMO), 0, ',', '.')]) }}</flux:button>
                    @endif
                </div>
            @endif

            <div class="{{ $grade }} border-b border-zinc-200 py-2 pe-4 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:border-zinc-700 dark:text-zinc-400">
                <span class="flex justify-center">
                    @if ($pagina)
                        <input type="checkbox" wire:click="alternarPagina" @checked($paginaToda) aria-label="{{ $paginaToda ? __('Desmarcar as chaves desta página') : __('Marcar as chaves desta página') }}" class="size-4 cursor-pointer accent-zinc-800 dark:accent-white">
                    @endif
                </span>
                <span>{{ __('Fornecedor') }}</span>
                <span class="max-md:hidden">{{ __('Nota') }}</span>
                <span class="max-md:hidden">{{ __('Natureza') }}</span>
                <span class="text-end">{{ __('Valor no SPED') }}</span>
                <span class="max-md:hidden">{{ __('Situação') }}</span>
            </div>

            @forelse ($this->chaves->getCollection()->groupBy(fn ($c) => $c->sped_data?->toDateString() ?? '') as $dia => $doDia)
                @php
                    $resumo = $this->resumoDias[$dia] ?? null;
                    $rotulo = $dia ? ucfirst(Carbon\CarbonImmutable::parse($dia)->locale('pt_BR')->translatedFormat('l, d/m/Y')) : __('Sem data no SPED');
                @endphp
                <section wire:key="dia-{{ $dia ?: 'sem' }}" aria-label="{{ $rotulo }}">
                    <div class="flex items-baseline justify-between gap-4 border-b border-zinc-200 bg-zinc-50 px-4 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $rotulo }}</span>
                        @if ($resumo)
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice(':n nota|:n notas', $resumo['n'], ['n' => $resumo['n']]) }} · {{ Fmt::money($resumo['total']) }}
                            </span>
                        @endif
                    </div>

                    <ul role="list" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($doDia as $c)
                            @php
                                $cnpj = substr($c->chave, 6, 14);
                                $numero = ltrim(substr($c->chave, 25, 9), '0') ?: '0';
                                $serie = ltrim(substr($c->chave, 22, 3), '0') ?: '0';
                                $nome = $c->fornecedor_nome ?? $this->fornecedores[$cnpj] ?? null;
                                $marcavel = $c->consultavel();
                                $semNota = $c->semNotaNoSped() && $c->status !== ChaveHistoricaStatus::Encontrada;
                                [$ponto, $cor] = $semNota ? ['bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'] : $tom($c->status);
                                $situacao = $semNota ? __('Cancelada no SPED') : $c->status->label();
                                $impostos = $c->sped_impostos ?? [];
                                $rotulosImposto = ['icms' => 'ICMS', 'icms_st' => 'ICMS-ST', 'ipi' => 'IPI', 'pis' => 'PIS', 'cofins' => 'COFINS', 'desconto' => __('Desconto'), 'frete' => __('Frete')];
                                $principais = array_intersect_key($impostos, array_flip(['icms', 'icms_st', 'ipi']));
                                $notaId = $this->notas[$c->chave] ?? null;
                            @endphp
                            <li wire:key="chave-{{ $c->id }}">
                                <{{ $marcavel ? 'label' : 'div' }} @class([
                                    $grade, 'py-2.5 pe-4 transition-colors',
                                    'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/[0.03] has-[:checked]:bg-zinc-100 dark:has-[:checked]:bg-white/[0.07]' => $marcavel,
                                ])>
                                    <span class="flex justify-center">
                                        @if ($marcavel)
                                            <input type="checkbox" wire:model.live="selecionados" value="{{ $c->id }}" aria-label="{{ __('Marcar a nota :n de :f', ['n' => $numero, 'f' => $nome ?? Fmt::cnpj($cnpj)]) }}" class="size-4 cursor-pointer accent-zinc-800 dark:accent-white">
                                        @endif
                                    </span>

                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $nome ?? Fmt::cnpj($cnpj) }}</p>
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                            @if ($nome)<span class="tabular-nums">{{ Fmt::cnpj($cnpj) }}</span> · @endif @if ($c->fornecedor_uf)<span>{{ $c->fornecedor_uf }}</span> · @endif
                                            <span class="font-mono tracking-tight" title="{{ __('Chave de acesso') }}">{{ trim(chunk_split($c->chave, 4, ' ')) }}</span>
                                        </p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-xs md:hidden {{ $cor }}">
                                            <span class="size-1.5 shrink-0 rounded-full {{ $ponto }}" aria-hidden="true"></span>{{ $situacao }}@if ($c->natureza) · {{ $c->natureza->label() }}@endif
                                        </p>
                                    </div>

                                    <span class="text-sm tabular-nums text-zinc-700 max-md:hidden dark:text-zinc-300">
                                        {{ $numero }}<span class="text-zinc-400 dark:text-zinc-500"> / {{ $serie }}</span>
                                    </span>

                                    <span class="truncate text-sm text-zinc-700 max-md:hidden dark:text-zinc-300" @if ($c->cfop) title="{{ __('CFOP :c', ['c' => $c->cfop]) }}" @endif>
                                        {{ $c->natureza?->label() ?? '—' }}
                                        @if ($c->cfop)
                                            <span class="block text-xs tabular-nums text-zinc-400 dark:text-zinc-500">CFOP {{ $c->cfop }}</span>
                                        @endif
                                    </span>

                                    {{-- Impostos do C100: os de crédito à vista, o resto no título. --}}
                                    <div class="text-end" @if ($impostos) title="{{ collect($impostos)->map(fn ($v, $k) => ($rotulosImposto[$k] ?? $k).' '.Fmt::money($v))->implode(' · ') }}" @endif>
                                        <p class="text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $c->sped_valor !== null ? Fmt::money($c->sped_valor) : '—' }}</p>
                                        @if ($principais)
                                            <p class="truncate text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                                {{ collect($principais)->map(fn ($v, $k) => $rotulosImposto[$k].' '.Fmt::money($v))->implode(' · ') }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="min-w-0 max-md:hidden">
                                        <p class="flex items-center gap-2 text-sm {{ $cor }}">
                                            <span @class(['size-2 shrink-0 rounded-full', $ponto, 'animate-pulse' => $c->status === ChaveHistoricaStatus::Pendente]) aria-hidden="true"></span>
                                            @if ($notaId)
                                                <a href="{{ route('documents.show', $notaId) }}" wire:navigate class="underline decoration-current/30 underline-offset-4 hover:decoration-current">{{ $situacao }}</a>
                                            @else
                                                {{ $situacao }}
                                            @endif
                                        </p>
                                        @if ($c->ultimo_erro && ! in_array($c->status, [ChaveHistoricaStatus::Encontrada, ChaveHistoricaStatus::Importada], true))
                                            <p class="mt-0.5 truncate ps-4 text-xs text-zinc-500 dark:text-zinc-400" title="{{ $c->ultimo_erro }}">{{ $c->ultimo_erro }}</p>
                                        @endif
                                    </div>
                                </{{ $marcavel ? 'label' : 'div' }}>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="px-4 py-12 text-center">
                    <flux:heading>{{ __('Nenhuma chave com esses filtros.') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" class="mt-2" wire:click="$set('status', ''); $set('mes', ''); $set('q', ''); $set('natureza', '')">{{ __('Limpar filtros') }}</flux:button>
                </div>
            @endforelse
        </div>

        <flux:pagination :paginator="$this->chaves" />
    @endif

    <flux:modal name="consultar-nfe" class="md:w-[32rem]">
        <form wire:submit="consultar" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">{{ trans_choice('Consulta completa de :count NF-e|Consulta completa de :count NF-e', count($selecionados), ['count' => count($selecionados)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('A busca começa agora, uma chave por vez; a situação na lista atualiza sozinha.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Consultar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
