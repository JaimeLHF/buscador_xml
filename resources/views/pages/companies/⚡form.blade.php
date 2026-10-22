<?php

use App\Concerns\CompanyValidationRules;
use App\Enums\Ambiente;
use App\Enums\Uf;
use App\Models\Company;
use App\Services\CnpjLookup\CnpjLookup;
use App\Support\Cnpj;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

    public ?string $lookupMessage = null;
    public string $lookupVariant = 'secondary';

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

    public function updatedCnpj(CnpjLookup $lookup): void
    {
        $this->lookupMessage = null;

        if (! Cnpj::isValid($this->cnpj)) {
            return;
        }

        $cnpj = Cnpj::from($this->cnpj);
        $this->cnpj = $cnpj->formatted();

        if ($this->company?->cnpj === $cnpj->value) {
            return;
        }

        $limiterKey = 'cnpj-lookup:'.Auth::id();

        if (RateLimiter::tooManyAttempts($limiterKey, 20)) {
            $this->setLookupMessage(__('Muitas consultas seguidas. Preencha manualmente ou aguarde um minuto.'), 'warning');

            return;
        }

        RateLimiter::hit($limiterKey);

        $data = $lookup->find($cnpj);

        if ($data === null) {
            $this->setLookupMessage(__('CNPJ não encontrado na base pública. Preencha os dados manualmente.'), 'warning');

            return;
        }

        $this->razao_social = $data->razaoSocial;
        $this->nome_fantasia = (string) $data->nomeFantasia;
        $this->uf = Uf::tryFrom((string) $data->uf)?->value ?? $this->uf;

        $data->isActive()
            ? $this->setLookupMessage(__('Dados preenchidos automaticamente (:source). Confira antes de salvar.', ['source' => $data->source]), 'success')
            : $this->setLookupMessage(__('Atenção: situação cadastral ":s" na Receita Federal.', ['s' => $data->situacao]), 'danger');
    }

    private function setLookupMessage(string $message, string $variant): void
    {
        $this->lookupMessage = $message;
        $this->lookupVariant = $variant;
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
    <flux:heading size="xl" level="1">{{ $company ? __('Editar empresa') : __('Nova empresa') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model.live.blur="cnpj" :label="__('CNPJ')" :description="__('Ao sair do campo, os dados são buscados na Receita.')" required autofocus class="font-mono">
                <x-slot name="iconTrailing">
                    <flux:icon.loading wire:loading wire:target="cnpj" variant="mini" />
                </x-slot>
            </flux:input>

            <flux:select wire:model="uf" :label="__('UF')" :placeholder="__('Selecione')" required>
                @foreach (Uf::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->value }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($lookupMessage)
            <flux:callout :variant="$lookupVariant" icon="information-circle" :heading="$lookupMessage" />
        @endif

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
