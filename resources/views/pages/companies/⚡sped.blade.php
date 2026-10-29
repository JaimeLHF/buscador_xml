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

    /**
     * "Selecionar todas" marca o filtro inteiro sem trazer os ids para o navegador (podem ser milhares):
     * a consulta refaz a busca no servidor.
     */
    public bool $todas = false;

    public function mount(Company $company): void
    {
        Gate::authorize('update', $company);

        $this->company = $company;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'mes', 'q', 'natureza'], true)) {
            $this->resetPage();
            // Mudou o filtro: "todas" já não significa as mesmas chaves.
            $this->todas = false;
        }
    }

    /** Soltou os arquivos na área de envio: lê na hora, sem um segundo clique. */
    public function updatedSpeds(): void
    {
        $this->importar();
    }

    /** Período coberto e valor das chaves da empresa, para o resumo do topo. @return array{de: ?string, ate: ?string, valor: ?string} */
    #[Computed]
    public function resumo(): array
    {
        $r = $this->company->chavesHistoricas()->toBase()->selectRaw('min(sped_data) as de, max(sped_data) as ate, sum(sped_valor) as valor')->first();

        return ['de' => $r?->de, 'ate' => $r?->ate, 'valor' => $r?->valor];
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

        $this->reset('speds', 'mes', 'q', 'natureza', 'selecionados', 'todas');
        unset($this->contagem, $this->resumo, $this->meses, $this->naturezas);
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

    public function limparFiltros(): void
    {
        $this->reset('status', 'mes', 'q', 'natureza', 'todas');
        $this->resetPage();
    }

    public function marcarFiltradas(): void
    {
        $this->todas = true;
        $this->selecionados = [];
    }

    public function desmarcar(): void
    {
        $this->todas = false;
        $this->selecionados = [];
    }

    #[Computed]
    public function marcadas(): int
    {
        return $this->todas ? $this->selecionaveisFiltradas : count($this->selecionados);
    }

    public function consultar(): void
    {
        Gate::authorize('update', $this->company);

        if (! MeuDanfeClient::configurado()) {
            Flux::toast(variant: 'danger', text: __('A consulta completa de NF-e não está configurada.'));

            return;
        }

        // Revalida no servidor: a seleção vem do navegador e só conta o que é desta empresa e ainda pode ser consultado.
        $ids = $this->todas
            ? $this->filtradas()->whereIn('status', self::statusSelecionaveis())->consultaveis()->pluck('id')->all()
            : array_values(array_unique(array_map('intval', $this->selecionados)));
        $n = SpedChaves::enfileirar($this->company, $ids);

        if ($n > 0) {
            Audit::record(AuditAcao::ConsultaNfeCompleta, $this->company->organization_id, descricao: $this->company->razao_social, dados: ['chaves' => $n]);
        }

        $this->desmarcar();
        unset($this->chaves, $this->contagem, $this->selecionaveisFiltradas, $this->marcadas);
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
    $comXml = $this->contagem[ChaveHistoricaStatus::Encontrada->value] ?? 0;
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');

    // Cor por situação, no tom da lista de notas: cor só onde há informação.
    $tom = fn (ChaveHistoricaStatus $st) => match ($st->cor()) {
        'amber' => ['bg-amber-500', 'text-zinc-600 dark:text-zinc-300'],
        'blue' => ['bg-sky-500', 'text-sky-700 dark:text-sky-400'],
        'green' => ['bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
        'red' => ['bg-red-500', 'text-red-700 dark:text-red-400'],
        default => ['bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'],
    };
    // Ordem da barra: o que já está resolvido primeiro, o que falta escolher por último.
    $ordem = [ChaveHistoricaStatus::Encontrada, ChaveHistoricaStatus::Pendente, ChaveHistoricaStatus::NaoEncontrada, ChaveHistoricaStatus::Erro, ChaveHistoricaStatus::Importada];
    $mesLabel = fn (?string $data) => $data ? ucfirst(Carbon\CarbonImmutable::parse($data)->locale('pt_BR')->translatedFormat('M/Y')) : null;
    $filtrosAtivos = $q !== '' || $mes !== '' || $natureza !== '' || $status !== '';
@endphp
<section class="w-full space-y-6" @if ($naFila > 0) wire:poll.5s @endif x-data="{ enviar: {{ $total === 0 ? 'true' : 'false' }}, arrastando: false, progresso: 0 }">
    {{-- Cabeçalho --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <nav class="flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400" aria-label="{{ __('Caminho') }}">
                <a href="{{ route('companies.index') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">{{ __('Empresas') }}</a>
                <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                <a href="{{ route('companies.show', $company) }}" wire:navigate class="truncate hover:text-zinc-900 dark:hover:text-white">{{ $company->nome_fantasia ?: $company->razao_social }}</a>
            </nav>
            <flux:heading size="xl" level="1" class="mt-1.5">{{ __('Chaves do SPED') }}</flux:heading>
            <flux:text class="mt-1 max-w-2xl">{{ __('Notas de entrada escrituradas no SPED. Escolha as que ainda não têm XML e baixe a NF-e completa em lote.') }}</flux:text>
        </div>

        @if ($total > 0)
            <flux:button icon="arrow-up-tray" x-on:click="enviar = ! enviar" x-bind:aria-expanded="enviar">{{ __('Enviar SPED') }}</flux:button>
        @endif
    </header>

    {{-- Envio: arrastar ou escolher; a leitura começa sozinha ao soltar os arquivos. --}}
    <div x-show="enviar" x-collapse @if ($total > 0) x-cloak @endif>
        <label
            x-on:dragover.prevent="arrastando = true"
            x-on:dragleave.prevent="arrastando = false"
            x-on:drop.prevent="arrastando = false; $refs.arquivos.files = $event.dataTransfer.files; $refs.arquivos.dispatchEvent(new Event('change'))"
            x-on:livewire-upload-start="progresso = 1"
            x-on:livewire-upload-progress="progresso = $event.detail.progress"
            x-on:livewire-upload-finish="progresso = 0"
            x-on:livewire-upload-error="progresso = 0"
            x-bind:class="arrastando ? 'border-zinc-900 bg-zinc-100 dark:border-white dark:bg-white/[0.06]' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600 dark:hover:border-zinc-500'"
            class="relative flex cursor-pointer flex-col items-center justify-center gap-3 border-2 border-dashed px-6 py-10 text-center transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-zinc-500"
        >
            <input x-ref="arquivos" type="file" wire:model="speds" multiple accept=".txt" class="sr-only" aria-describedby="envio-ajuda">

            <div wire:loading.remove wire:target="speds,importar" class="flex flex-col items-center gap-3">
                <span class="flex size-11 items-center justify-center border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon.document-arrow-up class="size-5 text-zinc-600 dark:text-zinc-300" />
                </span>
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">{{ __('Arraste os arquivos do SPED aqui') }}</p>
                    <p id="envio-ajuda" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('ou') }} <span class="font-medium text-zinc-900 underline underline-offset-4 dark:text-white">{{ __('escolha no computador') }}</span>
                        · {{ __('TXT do EFD ICMS/IPI baixado do ReceitanetBX, até 24 meses de uma vez') }}
                    </p>
                </div>
            </div>

            <div wire:loading.flex wire:target="speds,importar" class="w-full max-w-sm flex-col items-center gap-3" role="status">
                <flux:icon.arrow-path class="size-5 animate-spin text-zinc-500" />
                <p class="text-sm font-medium text-zinc-900 dark:text-white" x-text="progresso > 0 && progresso < 100 ? '{{ __('Enviando') }} ' + progresso + '%' : '{{ __('Lendo as chaves do SPED…') }}'"></p>
                <div class="h-1 w-full bg-zinc-200 dark:bg-zinc-700" aria-hidden="true">
                    <div class="h-1 bg-zinc-900 transition-[width] duration-300 dark:bg-white" x-bind:style="'width: ' + (progresso > 0 && progresso < 100 ? progresso : 100) + '%'"></div>
                </div>
            </div>
        </label>
        <flux:error name="speds" class="mt-2" />
        <flux:error name="speds.*" class="mt-2" />
    </div>

    @if ($importacao)
        <flux:callout icon="document-check" color="green">
            <flux:callout.heading>{{ __(':l notas de entrada no SPED', ['l' => $fmt($importacao['lidas'])]) }}</flux:callout.heading>
            <flux:callout.text>{{ __(':x já tinham o XML · :n novas aguardando seleção.', ['x' => $fmt($importacao['com_xml']), 'n' => $fmt($importacao['novas'])]) }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($total > 0)
        {{-- Visão geral: a barra mostra o quanto do SPED já tem XML; cada parte da legenda filtra a lista. --}}
        <div class="border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <p class="text-zinc-900 dark:text-white">
                    <span class="text-2xl font-semibold tabular-nums">{{ $fmt($comXml) }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('de :t notas com XML completo', ['t' => $fmt($total)]) }}</span>
                </p>
                <p class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                    @if ($this->resumo['de'])
                        {{ $mesLabel($this->resumo['de']) === $mesLabel($this->resumo['ate']) ? $mesLabel($this->resumo['de']) : $mesLabel($this->resumo['de']).' – '.$mesLabel($this->resumo['ate']) }} ·
                    @endif
                    {{ __(':v escriturados', ['v' => Fmt::money($this->resumo['valor'])]) }}
                </p>
            </div>

            <div class="mt-4 flex h-2 w-full gap-px overflow-hidden bg-zinc-100 dark:bg-zinc-800" role="img" aria-label="{{ __(':c de :t notas com XML completo', ['c' => $fmt($comXml), 't' => $fmt($total)]) }}">
                @foreach ($ordem as $st)
                    @if ($n = $this->contagem[$st->value] ?? 0)
                        <div class="{{ $tom($st)[0] }} h-full transition-[width] duration-500" style="width: {{ max(0.6, $n / $total * 100) }}%"></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Filtrar por situação') }}">
                <button type="button" wire:click="$set('status', '')" @class([
                    'inline-flex items-center gap-2 border px-2.5 py-1 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
                    'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $status === '',
                    'border-zinc-200 text-zinc-700 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-500' => $status !== '',
                ]) aria-pressed="{{ $status === '' ? 'true' : 'false' }}">
                    {{ __('Todas') }} <span class="tabular-nums opacity-60">{{ $fmt($total) }}</span>
                </button>
                @foreach ($ordem as $st)
                    @if ($n = $this->contagem[$st->value] ?? 0)
                        <button type="button" wire:click="$set('status', '{{ $st->value }}')" @class([
                            'inline-flex items-center gap-2 border px-2.5 py-1 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
                            'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $status === $st->value,
                            'border-zinc-200 text-zinc-700 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-500' => $status !== $st->value,
                        ]) aria-pressed="{{ $status === $st->value ? 'true' : 'false' }}">
                            <span @class(['size-2 shrink-0 rounded-full', $tom($st)[0], 'animate-pulse' => $st === ChaveHistoricaStatus::Pendente]) aria-hidden="true"></span>
                            {{ $st->label() }} <span class="tabular-nums opacity-60">{{ $fmt($n) }}</span>
                        </button>
                    @endif
                @endforeach
            </div>

            @if ($naFila > 0)
                <p class="mt-4 flex items-center gap-2 border-t border-zinc-100 pt-4 text-sm text-sky-700 dark:border-zinc-800 dark:text-sky-400" role="status">
                    <flux:icon.arrow-path variant="micro" class="animate-spin" />
                    {{ trans_choice('Consultando :n nota. A lista atualiza sozinha.|Consultando :n notas, uma por vez. A lista atualiza sozinha.', $naFila, ['n' => $fmt($naFila)]) }}
                </p>
            @endif
        </div>

        {{-- Filtros e seleção --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex w-full flex-wrap items-center gap-2 lg:w-auto">
                <div class="min-w-0 basis-full sm:basis-64">
                    <flux:input size="sm" wire:model.live.debounce.400ms="q" icon="magnifying-glass" :placeholder="__('Buscar por chave ou CNPJ')" clearable :aria-label="__('Buscar chave ou CNPJ do fornecedor')" />
                </div>
                @if ($this->naturezas)
                    <div class="w-full sm:w-52">
                        <flux:select size="sm" wire:model.live="natureza" :aria-label="__('Natureza da compra')">
                            <flux:select.option value="">{{ __('Todas as naturezas') }}</flux:select.option>
                            @foreach ($this->naturezas as $valor => $n)
                                <flux:select.option :value="$valor">{{ NaturezaCompra::from($valor)->label() }} ({{ $fmt($n) }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
                <div class="w-full sm:w-40">
                    <flux:select size="sm" wire:model.live="mes" :aria-label="__('Mês do SPED')">
                        <flux:select.option value="">{{ __('Todos os meses') }}</flux:select.option>
                        @foreach ($this->meses as $m)
                            <flux:select.option :value="$m">{{ $mesLabel($m.'-01') }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($filtrosAtivos)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="limparFiltros">{{ __('Limpar') }}</flux:button>
                @endif
            </div>

            @if ($this->selecionaveisFiltradas > 0 && ! $todas)
                <flux:button size="sm" variant="primary" icon="check" wire:click="marcarFiltradas">{{ __('Selecionar todas (:n)', ['n' => $fmt($this->selecionaveisFiltradas)]) }}</flux:button>
            @endif
        </div>

        {{-- Lista agrupada pelo dia de emissão no SPED --}}
        @php
            $grade = 'grid grid-cols-[2.75rem_minmax(0,1fr)_auto] items-center gap-x-3 md:grid-cols-[2.75rem_minmax(0,1fr)_11rem_9.5rem_11rem] md:gap-x-5';
        @endphp
        <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.class="opacity-60" wire:target="status,mes,q,natureza,gotoPage,nextPage,previousPage,importar,marcarFiltradas,desmarcar">
            <div class="{{ $grade }} border-b border-zinc-200 py-2 pe-4 text-xs font-medium tracking-wide text-zinc-500 uppercase max-md:hidden dark:border-zinc-700 dark:text-zinc-400" aria-hidden="true">
                <span></span>
                <span>{{ __('Fornecedor e nota') }}</span>
                <span>{{ __('Natureza') }}</span>
                <span class="text-end">{{ __('Valor no SPED') }}</span>
                <span>{{ __('Situação') }}</span>
            </div>

            @forelse ($this->chaves->getCollection()->groupBy(fn ($c) => $c->sped_data?->toDateString() ?? '') as $dia => $doDia)
                @php
                    $resumoDia = $this->resumoDias[$dia] ?? null;
                    $rotulo = $dia ? ucfirst(Carbon\CarbonImmutable::parse($dia)->locale('pt_BR')->translatedFormat('l, d/m/Y')) : __('Sem data no SPED');
                @endphp
                <section wire:key="dia-{{ $dia ?: 'sem' }}" aria-label="{{ $rotulo }}">
                    <div class="flex items-baseline justify-between gap-4 border-b border-zinc-200 bg-zinc-50 px-4 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $rotulo }}</span>
                        @if ($resumoDia)
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice(':n nota|:n notas', $resumoDia['n'], ['n' => $resumoDia['n']]) }} · {{ Fmt::money($resumoDia['total']) }}
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
                                $clicavel = $marcavel && ! $todas;
                                $semNota = $c->semNotaNoSped() && $c->status !== ChaveHistoricaStatus::Encontrada;
                                [$ponto, $cor] = $semNota ? ['bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'] : $tom($c->status);
                                $situacao = $semNota ? __('Cancelada no SPED') : $c->status->label();
                                $impostos = $c->sped_impostos ?? [];
                                $rotulosImposto = ['icms' => 'ICMS', 'icms_st' => 'ICMS-ST', 'ipi' => 'IPI', 'pis' => 'PIS', 'cofins' => 'COFINS', 'desconto' => __('Desconto'), 'frete' => __('Frete')];
                                $principais = array_intersect_key($impostos, array_flip(['icms', 'icms_st', 'ipi']));
                                $notaId = $this->notas[$c->chave] ?? null;
                                $chaveFormatada = trim(chunk_split($c->chave, 4, ' '));
                            @endphp
                            <li wire:key="chave-{{ $c->id }}" class="group/linha">
                                <{{ $clicavel ? 'label' : 'div' }} @class([
                                    $grade, 'py-3 pe-4 transition-colors has-[:checked]:bg-zinc-100 dark:has-[:checked]:bg-white/[0.07]',
                                    'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/[0.03]' => $clicavel,
                                    'opacity-60' => $semNota,
                                ])>
                                    <span class="flex justify-center">
                                        @if ($marcavel && $todas)
                                            <input type="checkbox" checked disabled aria-label="{{ __('Marcada (todas selecionadas)') }}" class="size-4 accent-zinc-800 dark:accent-white">
                                        @elseif ($marcavel)
                                            <input type="checkbox" wire:model.live="selecionados" value="{{ $c->id }}" aria-label="{{ __('Marcar a nota :n de :f', ['n' => $numero, 'f' => $nome ?? Fmt::cnpj($cnpj)]) }}" class="size-4 cursor-pointer accent-zinc-800 dark:accent-white">
                                        @endif
                                    </span>

                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $nome ?? Fmt::cnpj($cnpj) }}</p>
                                        <p class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            <span class="shrink-0 font-medium text-zinc-700 tabular-nums dark:text-zinc-300">{{ __('NF :n', ['n' => $numero]) }}<span class="font-normal text-zinc-400 dark:text-zinc-500">/{{ $serie }}</span></span>
                                            <span aria-hidden="true">·</span>
                                            @if ($nome)
                                                <span class="truncate tabular-nums">{{ Fmt::cnpj($cnpj) }}@if ($c->fornecedor_uf) · {{ $c->fornecedor_uf }}@endif</span>
                                            @elseif ($c->fornecedor_uf)
                                                <span>{{ $c->fornecedor_uf }}</span>
                                            @endif
                                            {{-- Chave de acesso: escondida até precisar, um clique copia. --}}
                                            <button type="button"
                                                x-data="{ copiada: false }"
                                                x-on:click.prevent.stop="navigator.clipboard.writeText('{{ $c->chave }}'); copiada = true; setTimeout(() => copiada = false, 1500)"
                                                class="relative z-10 inline-flex shrink-0 items-center gap-1 px-1 text-zinc-400 opacity-0 transition-opacity group-hover/linha:opacity-100 hover:text-zinc-900 focus-visible:opacity-100 dark:hover:text-white"
                                                title="{{ $chaveFormatada }}"
                                                aria-label="{{ __('Copiar a chave de acesso :c', ['c' => $chaveFormatada]) }}">
                                                <flux:icon.clipboard-document variant="micro" x-show="! copiada" />
                                                <flux:icon.check variant="micro" x-show="copiada" x-cloak />
                                                <span x-text="copiada ? '{{ __('Chave copiada') }}' : '{{ __('Copiar chave') }}'"></span>
                                            </button>
                                        </p>
                                        <p class="mt-1 flex items-center gap-1.5 text-xs md:hidden {{ $cor }}">
                                            <span class="size-1.5 shrink-0 rounded-full {{ $ponto }}" aria-hidden="true"></span>{{ $situacao }}@if ($c->natureza) <span class="text-zinc-500 dark:text-zinc-400">· {{ $c->natureza->label() }}</span>@endif
                                        </p>
                                    </div>

                                    <div class="min-w-0 max-md:hidden">
                                        @if ($c->natureza)
                                            <span class="inline-flex max-w-full items-center border border-zinc-200 px-1.5 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:text-zinc-300" title="{{ __('CFOP :c', ['c' => $c->cfop]) }}">
                                                <span class="truncate">{{ $c->natureza->label() }}</span>
                                            </span>
                                            <span class="mt-1 block text-xs tabular-nums text-zinc-400 dark:text-zinc-500">CFOP {{ $c->cfop }}</span>
                                        @else
                                            <span class="text-sm text-zinc-400">—</span>
                                        @endif
                                    </div>

                                    {{-- Impostos do C100: os de crédito à vista, o resto no título. --}}
                                    <div class="text-end" @if ($impostos) title="{{ collect($impostos)->map(fn ($v, $k) => ($rotulosImposto[$k] ?? $k).' '.Fmt::money($v))->implode(' · ') }}" @endif>
                                        <p class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $c->sped_valor !== null ? Fmt::money($c->sped_valor) : '—' }}</p>
                                        @if ($principais)
                                            <p class="mt-0.5 truncate text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                                {{ collect($principais)->map(fn ($v, $k) => $rotulosImposto[$k].' '.Fmt::money($v))->implode(' · ') }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="min-w-0 max-md:hidden">
                                        <p class="flex items-center gap-2 text-sm {{ $cor }}">
                                            <span @class(['size-2 shrink-0 rounded-full', $ponto, 'animate-pulse' => $c->status === ChaveHistoricaStatus::Pendente]) aria-hidden="true"></span>
                                            @if ($notaId)
                                                <a href="{{ route('documents.show', $notaId) }}" wire:navigate class="relative z-10 inline-flex items-center gap-1 underline decoration-current/30 underline-offset-4 hover:decoration-current">{{ __('Abrir nota') }}<flux:icon.arrow-up-right variant="micro" /></a>
                                            @else
                                                {{ $situacao }}
                                            @endif
                                        </p>
                                        @if ($c->ultimo_erro && ! in_array($c->status, [ChaveHistoricaStatus::Encontrada, ChaveHistoricaStatus::Importada], true))
                                            <p class="mt-0.5 truncate ps-4 text-xs text-zinc-500 dark:text-zinc-400" title="{{ $c->ultimo_erro }}">{{ $c->ultimo_erro }}</p>
                                        @endif
                                    </div>
                                </{{ $clicavel ? 'label' : 'div' }}>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="flex flex-col items-center gap-2 px-4 py-14 text-center">
                    <flux:icon.magnifying-glass class="size-6 text-zinc-400" />
                    <flux:heading>{{ __('Nenhuma chave com esses filtros.') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" wire:click="limparFiltros">{{ __('Limpar filtros') }}</flux:button>
                </div>
            @endforelse
        </div>

        <flux:pagination :paginator="$this->chaves" />

        {{-- Barra de ação: aparece presa ao pé da tela assim que há chaves marcadas. --}}
        @if ($this->marcadas)
            <div class="pointer-events-none sticky bottom-4 z-30 flex justify-center px-2">
                <div class="pointer-events-auto flex w-full max-w-2xl flex-wrap items-center justify-between gap-3 bg-zinc-900 px-4 py-3 text-white shadow-[0_12px_32px_-8px_rgb(0_0_0/0.45)] dark:bg-white dark:text-zinc-900" role="region" aria-label="{{ __('Chaves marcadas') }}">
                    <p class="text-sm">
                        <span class="font-semibold tabular-nums">{{ trans_choice(':count chave marcada|:count chaves marcadas', $this->marcadas, ['count' => $fmt($this->marcadas)]) }}</span>
                        @if ($todas)
                            <span class="opacity-60">· {{ __('todas do filtro') }}</span>
                        @endif
                    </p>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="desmarcar" class="px-3 py-1.5 text-sm opacity-75 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2">{{ __('Desmarcar') }}</button>
                        <flux:modal.trigger name="consultar-nfe">
                            <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap bg-white px-3.5 py-1.5 text-sm font-medium text-zinc-900 transition-colors hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-700">
                                <flux:icon.arrow-down-tray variant="micro" /><span class="sm:hidden">{{ __('Consultar') }}</span><span class="max-sm:hidden">{{ __('Consultar NF-e completa') }}</span>
                            </button>
                        </flux:modal.trigger>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <flux:modal name="consultar-nfe" class="md:w-[32rem]">
        <form wire:submit="consultar" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">{{ __('Consulta completa de :count NF-e', ['count' => $fmt($this->marcadas)]) }}</flux:heading>
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
