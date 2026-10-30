<?php

use App\Models\Company;
use App\Support\Fmt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** Para onde voltar depois da troca. O detalhe de uma nota ou fornecedor pode não existir na outra empresa. */
    #[Locked]
    public string $voltarPara = '';

    public function mount(): void
    {
        $this->voltarPara = match (true) {
            request()->routeIs('documents.show') => route('documents.index'),
            request()->routeIs('suppliers.show') => route('suppliers.index'),
            default => request()->fullUrl(),
        };
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization?->companies()->orderBy('razao_social')->get(['id', 'organization_id', 'cnpj', 'razao_social', 'nome_fantasia', 'uf', 'ativo']) ?? collect();
    }

    #[Computed]
    public function selecionada(): ?Company
    {
        return Auth::user()->selectedCompany();
    }

    public function selecionar(?int $companyId): void
    {
        Auth::user()->switchCompany($companyId === null ? null : Company::findOrFail($companyId));

        $this->redirect($this->voltarPara ?: route('documents.index'), navigate: true);
    }
}; ?>

<div>
    <flux:modal.trigger name="selecionar-empresa">
        <button type="button" class="cartao-empresa group flex w-full cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-start transition hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/60 dark:hover:border-zinc-600 dark:hover:bg-zinc-800" data-test="company-switcher">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600 dark:bg-zinc-700/70 dark:text-zinc-300">
                <flux:icon :name="$this->selecionada ? 'building-library' : 'squares-2x2'" variant="mini" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-[10px] font-semibold tracking-widest text-zinc-500 uppercase dark:text-zinc-400">{{ __('Empresa') }}</span>
                <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-white">
                    {{ $this->selecionada ? ($this->selecionada->nome_fantasia ?: $this->selecionada->razao_social) : __('Todas as empresas') }}
                </span>
            </span>
            <flux:icon name="arrows-right-left" variant="micro" class="shrink-0 text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
        </button>
    </flux:modal.trigger>

    <flux:modal name="selecionar-empresa" class="w-full md:w-[34rem]" :closable="false">
        <div x-data="{ busca: '' }" class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Selecionar empresa') }}</flux:heading>
                <div class="flex items-center gap-2">
                    <flux:badge size="sm">{{ trans_choice(':n empresa|:n empresas', $this->companies->count(), ['n' => $this->companies->count()]) }}</flux:badge>
                    <flux:modal.close>
                        <flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Fechar')" />
                    </flux:modal.close>
                </div>
            </div>

            <flux:input x-model="busca" icon="magnifying-glass" :placeholder="__('Buscar por nome ou CNPJ...')" :aria-label="__('Buscar empresa')" autofocus />

            <div class="-mx-2 max-h-[22rem] space-y-0.5 overflow-y-auto px-2">
                @php $item = 'item-empresa flex w-full cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2.5 text-start transition hover:bg-zinc-100 dark:hover:bg-zinc-800'; @endphp

                <button type="button" wire:click="selecionar(null)" x-show="busca === ''" class="{{ $item }} {{ $this->selecionada ? '' : 'bg-zinc-100 dark:bg-zinc-800' }}">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600 dark:bg-zinc-700/70 dark:text-zinc-300">
                        <flux:icon name="squares-2x2" variant="mini" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ __('Todas as empresas') }}</span>
                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ __('Visão consolidada') }} · {{ trans_choice(':n empresa|:n empresas', $this->companies->count(), ['n' => $this->companies->count()]) }}</span>
                    </span>
                    @unless ($this->selecionada)
                        <flux:icon name="check" variant="mini" class="shrink-0 text-zinc-900 dark:text-white" />
                    @endunless
                </button>

                @foreach ($this->companies as $i => $c)
                    @php $termo = Str::lower(Str::ascii($c->razao_social.' '.$c->nome_fantasia)).' '.$c->cnpj; @endphp
                    <button
                        type="button"
                        wire:key="empresa-{{ $c->id }}"
                        wire:click="selecionar({{ $c->id }})"
                        style="--i: {{ $i + 1 }}"
                        x-show="busca === '' || @js($termo).includes(busca.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')) || (busca.replace(/[^0-9a-z]/gi, '') !== '' && @js($c->cnpj).includes(busca.replace(/[^0-9a-z]/gi, '').toUpperCase()))"
                        class="{{ $item }} {{ $this->selecionada?->id === $c->id ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}"
                    >
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600 dark:bg-zinc-700/70 dark:text-zinc-300">
                            <flux:icon name="building-library" variant="mini" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $c->razao_social }}</span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ Fmt::cnpj($c->cnpj) }} · {{ $c->uf->value }}@unless ($c->ativo) · {{ __('inativa') }}@endunless
                            </span>
                        </span>
                        @if ($this->selecionada?->id === $c->id)
                            <flux:icon name="check" variant="mini" class="shrink-0 text-zinc-900 dark:text-white" />
                        @endif
                    </button>
                @endforeach

                @if ($this->companies->isEmpty())
                    <flux:text class="px-2.5 py-6 text-center">{{ __('Nenhuma empresa cadastrada ainda.') }}</flux:text>
                @endif
            </div>

            <div class="flex items-center justify-between gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="ghost" size="sm" icon="cog-6-tooth" :href="route('companies.index')" wire:navigate>{{ __('Gerenciar empresas') }}</flux:button>
                <flux:modal.close>
                    <flux:button size="sm">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
