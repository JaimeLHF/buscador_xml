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
    public function escritorio(): ?App\Models\Certificate
    {
        return Auth::user()->currentOrganization->officeCertificate;
    }

    #[Computed]
    public function empresasPeloContador(): int
    {
        return Auth::user()->currentOrganization->companies()->where('modo_busca', App\Enums\ModoBusca::ContadorSc)->count();
    }

    #[Computed]
    public function companies()
    {
        $term = trim($this->search);
        $cnpj = preg_replace('/[.\/\-\s]/', '', strtoupper($term));

        return Auth::user()->currentOrganization->companies()
            ->with(['activeCertificate', 'syncStates', 'organization.officeCertificate'])
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

    {{-- Certificado do escritório: só aparece aberto quando alguma empresa depende dele ou ele já foi enviado. --}}
    <div class="border border-zinc-200 p-4 dark:border-zinc-700" x-data="{ aberto: {{ $this->escritorio || $this->empresasPeloContador ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <flux:heading level="2">{{ __('Certificado do contador (empresas de SC)') }}</flux:heading>
                <flux:text size="sm">{{ __('Para buscar notas de empresas de Santa Catarina sem o certificado de cada uma: um único certificado do escritório contábil (e-CNPJ ou e-CPF do contador) serve para todas as empresas marcadas com "Certificado do contador".') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                @if ($this->escritorio)
                    <x-certificate-badge :certificate="$this->escritorio" />
                @elseif ($this->empresasPeloContador)
                    <flux:badge size="sm" color="red">{{ __('Falta enviar') }}</flux:badge>
                @endif
                <flux:button size="sm" variant="ghost" x-on:click="aberto = ! aberto" x-text="aberto ? '{{ __('Fechar') }}' : '{{ __('Configurar') }}'" />
            </div>
        </div>

        <div x-show="aberto" x-cloak class="mt-4 grid gap-6 md:grid-cols-2">
            <div class="space-y-2 text-sm">
                @if ($cert = $this->escritorio)
                    <x-campos :itens="[
                        __('Titular') => $cert->titular,
                        strlen($cert->cnpj_certificado) === 11 ? __('CPF') : __('CNPJ') => $cert->documentoFormatado(),
                        __('Validade') => $cert->valid_from->format('d/m/Y').' – '.$cert->valid_to->format('d/m/Y'),
                        __('Empresas usando') => trans_choice(':n empresa|:n empresas', $this->empresasPeloContador, ['n' => $this->empresasPeloContador]),
                    ]" />
                @else
                    <flux:text size="sm">{{ __('Nenhum certificado do escritório enviado.') }}</flux:text>
                @endif
                <flux:text size="sm">{{ __('A SEF-SC só libera as notas se o titular do certificado estiver cadastrado como contador da empresa. Depois, em cada empresa de SC, escolha "Certificado do contador" em Editar.') }}</flux:text>
            </div>

            @if (auth()->user()->managesCurrentOrganization())
                <form method="POST" action="{{ route('office-certificate.store') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <flux:input type="file" name="pfx" accept=".pfx,.p12" :label="$this->escritorio ? __('Trocar certificado do escritório (.pfx)') : __('Certificado do escritório (.pfx)')" required />
                    <flux:input type="password" name="password" :label="__('Senha do certificado')" required autocomplete="off" viewable />
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Enviar certificado do escritório') }}</flux:button>
                </form>
            @endif
        </div>
    </div>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Buscar por razão social, fantasia ou CNPJ')" class="max-w-md" />

    <flux:table :paginate="$this->companies">
        <flux:table.columns>
            <flux:table.column>{{ __('Empresa') }}</flux:table.column>
            <flux:table.column>{{ __('CNPJ') }}</flux:table.column>
            <flux:table.column>{{ __('UF') }}</flux:table.column>
            <flux:table.column>{{ __('Como busca') }}</flux:table.column>
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
                        <flux:badge size="sm" :color="$company->modo_busca->badgeColor()">{{ $company->modo_busca === App\Enums\ModoBusca::ContadorSc ? __('Contador (SC)') : __('Próprio certificado') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$company->ambiente === App\Enums\Ambiente::Producao ? 'sky' : 'amber'">
                            {{ $company->ambiente->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <x-certificate-badge :certificate="$company->certificadoDeBusca()" />
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$company->ativo ? 'green' : 'zinc'">
                            {{ $company->ativo ? __('Ativa') : __('Inativa') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">{{ __('Nenhuma empresa cadastrada.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
