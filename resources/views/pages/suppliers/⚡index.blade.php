<?php

use App\Models\DfeDocument;
use App\Support\Fmt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fornecedores')] class extends Component {
    use WithPagination;

    #[Url] public string $q = '';
    #[Url] public string $company_id = '';
    #[Url] public string $order = 'docs';

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization->companies()->orderBy('razao_social')->get(['id', 'razao_social', 'cnpj']);
    }

    #[Computed]
    public function suppliers()
    {
        $companyIds = Auth::user()->currentOrganization->companies()->select('id');

        $query = DfeDocument::query()
            ->select([
                'emitente_cnpj',
                DB::raw('max(emitente_nome) as emitente_nome'),
                DB::raw('count(*) as total_docs'),
                DB::raw('coalesce(sum(valor_total), 0) as total_valor'),
                DB::raw('max(data_emissao) as ultima_emissao'),
                DB::raw('min(data_emissao) as primeira_emissao'),
                DB::raw("count(*) filter (where tipo = 'nfe') as nfe_count"),
                DB::raw("count(*) filter (where tipo = 'cte') as cte_count"),
            ])
            ->whereIn('company_id', $companyIds)
            ->whereNotNull('emitente_cnpj')
            ->when($this->company_id, fn ($q) => $q->where('company_id', (int) $this->company_id))
            ->when($this->q, function ($q) {
                $term = trim($this->q);
                $digits = preg_replace('/\D/', '', $term);

                $q->where(fn ($q) => $q
                    ->where('emitente_nome', 'ilike', '%'.addcslashes($term, '%_').'%')
                    ->when(strlen($digits) >= 3, fn ($q) => $q->orWhere('emitente_cnpj', 'like', $digits.'%')));
            })
            ->groupBy('emitente_cnpj');

        $orderBy = match ($this->order) {
            'nome' => 'emitente_nome',
            'valor' => 'total_valor',
            'recente' => 'ultima_emissao',
            default => 'total_docs',
        };

        $direction = $this->order === 'nome' ? 'asc' : 'desc';

        return $query->orderBy($orderBy, $direction)->paginate(25);
    }
}; ?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    {{-- Cabeçalho --}}
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Fornecedores') }}</flux:heading>
        <flux:text>{{ __('Emitentes que aparecem nas notas recebidas pela organização.') }}</flux:text>
    </div>

    {{-- Busca, filtros e ordenação --}}
    <div class="flex flex-wrap gap-2">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.live.debounce.400ms="q" icon="magnifying-glass" :placeholder="__('Nome ou CNPJ do fornecedor')" :aria-label="__('Buscar fornecedor')" clearable />
        </div>
        @if ($this->companies->count() > 1)
            <flux:select wire:model.live="company_id" class="w-48 shrink-0" :aria-label="__('Filtrar por empresa')">
                <flux:select.option value="">{{ __('Todas as empresas') }}</flux:select.option>
                @foreach ($this->companies as $c)
                    <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:select wire:model.live="order" class="w-40 shrink-0" :aria-label="__('Ordenar por')">
            <flux:select.option value="docs">{{ __('Mais notas') }}</flux:select.option>
            <flux:select.option value="valor">{{ __('Maior valor') }}</flux:select.option>
            <flux:select.option value="recente">{{ __('Mais recente') }}</flux:select.option>
            <flux:select.option value="nome">{{ __('Nome A–Z') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Tabela de fornecedores (mesmo padrão de /documents) --}}
    @php
        $grade = 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,1fr)_11rem_7rem_8.5rem_6rem]';
    @endphp

    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-60" wire:target="q,company_id,order,gotoPage,nextPage,previousPage">
        <div class="{{ $grade }} border-b border-zinc-200 px-5 py-2.5 text-xs font-medium tracking-wide text-zinc-500 uppercase max-md:hidden dark:border-zinc-700 dark:text-zinc-400" aria-hidden="true">
            <span>{{ __('Fornecedor') }}</span>
            <span>{{ __('CNPJ') }}</span>
            <span class="text-end">{{ __('Documentos') }}</span>
            <span class="text-end">{{ __('Valor total') }}</span>
            <span class="text-end">{{ __('Última nota') }}</span>
        </div>

        <ul role="list" class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($this->suppliers as $supplier)
                @php
                    $ultimaEmissao = $supplier->ultima_emissao ? \Carbon\CarbonImmutable::parse($supplier->ultima_emissao)->timezone(Fmt::TIMEZONE) : null;
                    $ultimaLabel = match (true) {
                        ! $ultimaEmissao => '—',
                        $ultimaEmissao->isToday() => __('Hoje'),
                        $ultimaEmissao->isYesterday() => __('Ontem'),
                        $ultimaEmissao->isCurrentYear() => $ultimaEmissao->format('d/m'),
                        default => $ultimaEmissao->format('d/m/Y'),
                    };
                @endphp
                <li wire:key="s-{{ $supplier->emitente_cnpj }}" class="group relative {{ $grade }} cursor-pointer px-5 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                    {{-- Fornecedor --}}
                    <div class="min-w-0">
                        <a href="{{ route('suppliers.show', $supplier->emitente_cnpj) }}" wire:navigate class="block truncate font-medium text-zinc-800 after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-zinc-400 focus-visible:after:ring-inset dark:text-white">
                            {{ $supplier->emitente_nome ?? __('Sem nome') }}
                        </a>
                        {{-- Mobile --}}
                        <p class="mt-0.5 text-xs text-zinc-500 md:hidden dark:text-zinc-400">
                            {{ Fmt::cnpj($supplier->emitente_cnpj) }} · {{ Fmt::money($supplier->total_valor) }}
                        </p>
                    </div>

                    {{-- CNPJ --}}
                    <span class="font-mono text-sm text-zinc-500 max-md:hidden dark:text-zinc-400">{{ Fmt::cnpj($supplier->emitente_cnpj) }}</span>

                    {{-- Documentos --}}
                    <div class="flex items-center justify-end gap-1.5 max-md:hidden">
                        @if ($supplier->nfe_count > 0)
                            <flux:badge size="sm" color="zinc">{{ $supplier->nfe_count }} NF-e</flux:badge>
                        @endif
                        @if ($supplier->cte_count > 0)
                            <flux:badge size="sm" color="zinc">{{ $supplier->cte_count }} CT-e</flux:badge>
                        @endif
                    </div>

                    {{-- Valor total --}}
                    <span class="text-end text-sm font-medium tabular-nums text-zinc-900 whitespace-nowrap dark:text-white">{{ Fmt::money($supplier->total_valor) }}</span>

                    {{-- Última nota --}}
                    <span class="text-end text-sm tabular-nums text-zinc-500 whitespace-nowrap max-md:hidden dark:text-zinc-400">{{ $ultimaLabel }}</span>
                </li>
            @empty
                <li class="px-5 py-12 text-center">
                    <flux:heading>
                        @if ($q !== '')
                            {{ __('Nenhum fornecedor encontrado com essa busca.') }}
                        @else
                            {{ __('Nenhum fornecedor encontrado.') }}
                        @endif
                    </flux:heading>
                    @if ($q !== '')
                        <flux:button size="sm" variant="ghost" class="mt-2" wire:click="$set('q', '')">{{ __('Limpar busca') }}</flux:button>
                    @endif
                </li>
            @endforelse
        </ul>
    </div>

    <flux:pagination :paginator="$this->suppliers" />
</section>
