<?php

use App\Enums\DocumentoTipo;
use App\Models\DfeDocument;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fornecedor')] class extends Component {
    use WithPagination;

    public string $cnpj;

    #[Url] public string $tipo = '';
    #[Url] public string $company_id = '';
    #[Url] public string $de = '';
    #[Url] public string $ate = '';

    public function mount(string $cnpj): void
    {
        $this->cnpj = $cnpj;

        // Verify supplier belongs to user's organization
        $exists = DfeDocument::query()
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->where('emitente_cnpj', $this->cnpj)
            ->exists();

        abort_unless($exists, 404);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    private function baseQuery()
    {
        return DfeDocument::query()
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->where('emitente_cnpj', $this->cnpj)
            ->when(DocumentoTipo::tryFrom($this->tipo), fn ($q, $t) => $q->where('tipo', $t))
            ->when($this->company_id, fn ($q) => $q->where('company_id', (int) $this->company_id))
            ->when($this->de, fn ($q) => $q->where('data_emissao', '>=', CarbonImmutable::parse($this->de, Fmt::TIMEZONE)->startOfDay()->utc()))
            ->when($this->ate, fn ($q) => $q->where('data_emissao', '<=', CarbonImmutable::parse($this->ate, Fmt::TIMEZONE)->endOfDay()->utc()));
    }

    #[Computed]
    public function supplier(): object
    {
        return DfeDocument::query()
            ->select([
                'emitente_cnpj',
                DB::raw('max(emitente_nome) as emitente_nome'),
                DB::raw('count(*) as total_docs'),
                DB::raw('coalesce(sum(valor_total), 0) as total_valor'),
                DB::raw('max(data_emissao) as ultima_emissao'),
                DB::raw('min(data_emissao) as primeira_emissao'),
            ])
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->where('emitente_cnpj', $this->cnpj)
            ->groupBy('emitente_cnpj')
            ->first();
    }

    #[Computed]
    public function stats(): array
    {
        $base = $this->baseQuery()->toBase();

        return [
            'total' => (clone $base)->count(),
            'valor' => (float) (clone $base)->sum('valor_total'),
            'nfe' => (clone $base)->where('tipo', DocumentoTipo::Nfe)->count(),
            'cte' => (clone $base)->where('tipo', DocumentoTipo::Cte)->count(),
        ];
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization->companies()->orderBy('razao_social')->get(['id', 'razao_social', 'cnpj']);
    }

    /** Empresas desta organização que receberam notas deste fornecedor. */
    #[Computed]
    public function empresasDestinatarias(): array
    {
        return DfeDocument::query()
            ->select('company_id', DB::raw('count(*) as total'))
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->where('emitente_cnpj', $this->cnpj)
            ->groupBy('company_id')
            ->with('company:id,razao_social,cnpj')
            ->get()
            ->map(fn ($r) => ['nome' => $r->company->razao_social, 'cnpj' => $r->company->cnpj, 'total' => $r->total])
            ->all();
    }

    #[Computed]
    public function documents()
    {
        return $this->baseQuery()
            ->with('company:id,razao_social,nome_fantasia,cnpj')
            ->orderByDesc('data_emissao')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <div>
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('suppliers.index')" wire:navigate class="mb-2">{{ __('Fornecedores') }}</flux:button>

        <flux:heading size="xl" level="1">{{ $this->supplier->emitente_nome ?? __('Fornecedor') }}</flux:heading>
        <flux:text class="font-mono">{{ Fmt::cnpj($this->cnpj) }}</flux:text>
    </div>

    {{-- Cards de resumo --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Total de notas') }}</flux:text>
            <flux:heading size="xl">{{ number_format($this->supplier->total_docs, 0, ',', '.') }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Valor total') }}</flux:text>
            <flux:heading size="xl">{{ Fmt::money($this->supplier->total_valor) }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Primeira nota') }}</flux:text>
            <flux:heading size="xl">{{ $this->supplier->primeira_emissao ? \Carbon\CarbonImmutable::parse($this->supplier->primeira_emissao)->timezone(Fmt::TIMEZONE)->format('d/m/Y') : '—' }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Última nota') }}</flux:text>
            <flux:heading size="xl">{{ $this->supplier->ultima_emissao ? \Carbon\CarbonImmutable::parse($this->supplier->ultima_emissao)->timezone(Fmt::TIMEZONE)->format('d/m/Y') : '—' }}</flux:heading>
        </flux:card>
    </div>

    {{-- Empresas destinatárias --}}
    @if (count($this->empresasDestinatarias) > 1)
        <flux:card class="space-y-3">
            <flux:heading size="lg" level="2">{{ __('Empresas que receberam notas deste fornecedor') }}</flux:heading>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->empresasDestinatarias as $dest)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $dest['nome'] }}</span>
                        <flux:badge color="zinc" size="sm">{{ trans_choice(':n nota|:n notas', $dest['total'], ['n' => $dest['total']]) }}</flux:badge>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    {{-- Filtros --}}
    <div class="flex flex-wrap gap-2">
        <flux:radio.group wire:model.live="tipo" variant="segmented" :aria-label="__('Tipo de documento')">
            <flux:radio value="" :label="__('Todos (:n)', ['n' => $this->stats['total']])" class="max-sm:px-2 max-sm:text-xs" />
            @if ($this->stats['nfe'] > 0)
                <flux:radio value="nfe" :label="__('NF-e (:n)', ['n' => $this->stats['nfe']])" class="max-sm:px-2 max-sm:text-xs" />
            @endif
            @if ($this->stats['cte'] > 0)
                <flux:radio value="cte" :label="__('CT-e (:n)', ['n' => $this->stats['cte']])" class="max-sm:px-2 max-sm:text-xs" />
            @endif
        </flux:radio.group>

        @if ($this->companies->count() > 1)
            <flux:select wire:model.live="company_id" class="w-48 shrink-0" :aria-label="__('Filtrar por empresa')">
                <flux:select.option value="">{{ __('Todas as empresas') }}</flux:select.option>
                @foreach ($this->companies as $c)
                    <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:input type="date" wire:model.live="de" :aria-label="__('De')" class="w-36" />
        <flux:input type="date" wire:model.live="ate" :aria-label="__('Até')" class="w-36" />
    </div>

    {{-- Lista de documentos --}}
    @php
        $grade = 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[7rem_minmax(0,1fr)_8rem_8.5rem_5.5rem]';
        $estados = [
            'pronto' => ['XML pronto', 'bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
            'aguardando' => ['XML a caminho', 'bg-sky-500', 'text-sky-700 dark:text-sky-400'],
            'resumo' => ['Só resumo', 'bg-zinc-400', 'text-zinc-500 dark:text-zinc-400'],
            'cancelada' => ['Cancelada', 'bg-red-500', 'text-red-700 dark:text-red-400'],
        ];
    @endphp

    <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.class="opacity-60" wire:target="tipo,company_id,de,ate,gotoPage,nextPage,previousPage">
        <div class="{{ $grade }} border-b border-zinc-200 px-4 py-2 text-xs font-medium tracking-wide text-zinc-500 uppercase max-md:hidden dark:border-zinc-700 dark:text-zinc-400" aria-hidden="true">
            <span>{{ __('Emissão') }}</span>
            <span>{{ __('Destinatário') }}</span>
            <span class="text-end">{{ __('Valor') }}</span>
            <span>{{ __('Estado') }}</span>
            <span class="text-end">{{ __('Arquivos') }}</span>
        </div>

        @forelse ($this->documents as $doc)
            @php
                [$estadoTexto, $estadoPonto, $estadoCor] = $estados[$doc->estado()];
            @endphp
            <div wire:key="doc-{{ $doc->id }}" class="group relative {{ $grade }} border-b border-zinc-100 px-4 py-2.5 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-white/[0.03]">
                <span class="text-sm tabular-nums text-zinc-500 max-md:hidden dark:text-zinc-400">
                    {{ $doc->data_emissao?->timezone(Fmt::TIMEZONE)->format('d/m/Y') }}
                </span>

                <div class="min-w-0">
                    <a href="{{ route('documents.show', $doc) }}" wire:navigate class="block truncate font-medium text-zinc-900 after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-zinc-400 focus-visible:after:ring-inset dark:text-white">
                        @if ($doc->tipo === DocumentoTipo::Cte)
                            <span class="me-1.5 inline-block border border-zinc-300 px-1 py-px align-[1px] text-[10px] font-semibold tracking-wide text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">CT-e</span>
                        @endif
                        {{ $doc->company->nome_fantasia ?: $doc->company->razao_social }}
                    </a>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 md:hidden">{{ $doc->data_emissao?->timezone(Fmt::TIMEZONE)->format('d/m/Y') }} · {{ Fmt::money($doc->valor_total) }}</p>
                </div>

                <span class="text-end text-sm font-medium tabular-nums text-zinc-900 max-md:hidden dark:text-white">{{ Fmt::money($doc->valor_total) }}</span>

                <span class="flex items-center gap-2 text-sm max-md:hidden {{ $estadoCor }}">
                    <span class="size-2 shrink-0 rounded-full {{ $estadoPonto }}" aria-hidden="true"></span>{{ __($estadoTexto) }}
                </span>

                <div class="relative z-10 flex justify-end gap-0.5 max-md:hidden">
                    @if ($doc->tem_xml_completo)
                        <flux:tooltip :content="__('Baixar XML')">
                            <flux:button size="sm" variant="subtle" square icon="code-bracket" :href="route('documents.xml', $doc)" :aria-label="__('Baixar XML')" />
                        </flux:tooltip>
                        <flux:tooltip :content="__('Abrir DANFE')">
                            <flux:button size="sm" variant="subtle" square icon="document-text" :href="route('documents.danfe', $doc)" target="_blank" :aria-label="__('Abrir DANFE')" />
                        </flux:tooltip>
                    @else
                        <flux:text size="sm" class="pe-2 text-zinc-400 dark:text-zinc-500">—</flux:text>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-12 text-center">
                <flux:heading>{{ __('Nenhuma nota encontrada com esses filtros.') }}</flux:heading>
            </div>
        @endforelse
    </div>

    <flux:pagination :paginator="$this->documents" />
</section>
