<?php

use App\Concerns\CompanyValidationRules;
use App\Enums\Ambiente;
use App\Enums\Uf;
use App\Models\Company;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Empresa')] class extends Component {
    use CompanyValidationRules;

    public ?Company $company = null;

    public string $cnpj = '';
    public string $razao_social = '';
    public string $nome_fantasia = '';
    public string $uf = '';
    public int $ambiente = Ambiente::Homologacao->value;
    public bool $ativo = true;
    public bool $manifestacao_automatica = false;

    public function mount(?Company $company = null): void
    {
        if ($company?->exists) {
            Gate::authorize('update', $company);

            $this->company = $company;
            $this->fill([
                'cnpj' => $company->cnpjValue()->formatted(),
                'razao_social' => $company->razao_social,
                'nome_fantasia' => (string) $company->nome_fantasia,
                'uf' => $company->uf->value,
                'ambiente' => $company->ambiente->value,
                'ativo' => $company->ativo,
                'manifestacao_automatica' => $company->manifestacao_automatica,
            ]);

            return;
        }

        Gate::authorize('create', Company::class);
    }

    public function save(): void
    {
        $organizationId = $this->company?->organization_id ?? Auth::user()->current_organization_id;

        $validated = $this->validate(
            $this->companyRules($organizationId, $this->company?->id),
            attributes: $this->companyAttributes(),
        );

        if ($this->company) {
            Gate::authorize('update', $this->company);
            $this->company->update($validated);
            $company = $this->company;
        } else {
            Gate::authorize('create', Company::class);
            $company = Auth::user()->currentOrganization->companies()->create($validated);
        }

        Flux::toast(variant: 'success', text: __('Empresa salva.'));

        $this->redirectRoute('companies.show', $company, navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:heading size="xl">{{ $company ? __('Editar empresa') : __('Nova empresa') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="cnpj" :label="__('CNPJ')" :description="__('Aceita CNPJ numérico ou alfanumérico.')" required autofocus class="font-mono" />

            <flux:select wire:model="uf" :label="__('UF')" :placeholder="__('Selecione')" required>
                @foreach (Uf::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->value }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:input wire:model="razao_social" :label="__('Razão social')" required />
        <flux:input wire:model="nome_fantasia" :label="__('Nome fantasia')" />

        <flux:radio.group wire:model="ambiente" :label="__('Ambiente SEFAZ')">
            @foreach (Ambiente::cases() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        <flux:switch wire:model="ativo" :label="__('Sincronização ativa')" />
        <flux:switch wire:model="manifestacao_automatica" :label="__('Enviar Ciência da Operação automaticamente')"
            :description="__('Ao receber só o resumo da NF-e, envia o evento 210210 para liberar o XML completo.')" />

        <div class="flex gap-3">
            <flux:button variant="primary" type="submit">{{ __('Salvar') }}</flux:button>
            <flux:button :href="$company ? route('companies.show', $company) : route('companies.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
        </div>
    </form>
</section>
