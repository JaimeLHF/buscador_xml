<?php

use App\Models\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Empresas')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Company::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function companies()
    {
        $term = trim($this->search);
        $cnpj = preg_replace('/[.\/\-\s]/', '', strtoupper($term));

        return Auth::user()->currentOrganization->companies()
            ->with(['activeCertificate', 'syncStates'])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('razao_social', 'ilike', "%{$term}%")
                ->orWhere('nome_fantasia', 'ilike', "%{$term}%")
                ->orWhere('cnpj', 'like', "%{$cnpj}%")))
            ->orderBy('razao_social')
            ->paginate(20);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Empresas') }}</flux:heading>

        @can('create', App\Models\Company::class)
            <flux:button variant="primary" icon="plus" :href="route('companies.create')" wire:navigate>
                {{ __('Nova empresa') }}
            </flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Buscar por razão social, fantasia ou CNPJ')" class="max-w-md" />

    <flux:table :paginate="$this->companies">
        <flux:table.columns>
            <flux:table.column>{{ __('Empresa') }}</flux:table.column>
            <flux:table.column>{{ __('CNPJ') }}</flux:table.column>
            <flux:table.column>{{ __('UF') }}</flux:table.column>
            <flux:table.column>{{ __('Ambiente') }}</flux:table.column>
            <flux:table.column>{{ __('Certificado') }}</flux:table.column>
            <flux:table.column>{{ __('Situação') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->companies as $company)
                <flux:table.row :key="$company->id">
                    <flux:table.cell>
                        <flux:link :href="route('companies.show', $company)" wire:navigate>{{ $company->razao_social }}</flux:link>
                        @if ($company->nome_fantasia)
                            <flux:text size="sm">{{ $company->nome_fantasia }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono">{{ $company->cnpjValue()->formatted() }}</flux:table.cell>
                    <flux:table.cell>{{ $company->uf->value }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$company->ambiente === App\Enums\Ambiente::Producao ? 'sky' : 'amber'">
                            {{ $company->ambiente->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <x-certificate-badge :certificate="$company->activeCertificate" />
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$company->ativo ? 'green' : 'zinc'">
                            {{ $company->ativo ? __('Ativa') : __('Inativa') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">{{ __('Nenhuma empresa cadastrada.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
