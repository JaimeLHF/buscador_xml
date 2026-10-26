<?php

use App\Concerns\CompanyValidationRules;
use App\Enums\Ambiente;
use App\Enums\ModoBusca;
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
    public bool $buscar_cte = false;
    public string $modo_busca = 'certificado_empresa';

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
                'buscar_cte' => $company->buscar_cte,
                'modo_busca' => $company->modo_busca->value,
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
            ? $this->setLookupMessage(__('Dados preenchidos automaticamente. Confira antes de salvar.'), 'success')
            : $this->setLookupMessage(__('Atenção: situação cadastral ":s" na Receita Federal.', ['s' => $data->situacao]), 'danger');
    }

    #[Livewire\Attributes\Computed]
    public function certificadoEscritorio(): ?App\Models\Certificate
    {
        return Auth::user()->currentOrganization->officeCertificate;
    }

    private function setLookupMessage(string $message, string $variant): void
    {
        $this->lookupMessage = $message;
        $this->lookupVariant = $variant;
    }

    /** O serviço da SEF-SC só existe em produção e não permite manifestar: o formulário já ajusta os dois campos. */
    public function updatedModoBusca(): void
    {
        if ($this->modo_busca === ModoBusca::ContadorSc->value) {
            $this->ambiente = Ambiente::Producao->value;
            $this->manifestacao_automatica = false;
        }
    }

    public function save(): void
    {
        $organizationId = $this->company?->organization_id ?? Auth::user()->current_organization_id;
        $this->updatedModoBusca();

        if ($this->modo_busca === ModoBusca::ContadorSc->value && $this->uf !== Uf::SC->value) {
            $this->addError('modo_busca', __('A busca pelo certificado do contador só existe para empresas de Santa Catarina. Escolha "Certificado da própria empresa".'));

            return;
        }

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

<section class="mx-auto w-full max-w-2xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ $company ? __('Editar empresa') : __('Nova empresa') }}</flux:heading>
        <flux:subheading>{{ $company ? __('Altere os dados cadastrais e de sincronização.') : __('Cadastre uma empresa para começar a buscar notas fiscais.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-8">
        {{-- Identificação --}}
        <flux:fieldset>
            <flux:legend>{{ __('Identificação') }}</flux:legend>

            <div class="space-y-4">
                <flux:input
                    wire:model.live.blur="cnpj"
                    :label="__('CNPJ')"
                    :description="__('Os dados da empresa são buscados automaticamente.')"
                    required
                    autofocus
                    class="font-mono"
                    placeholder="00.000.000/0000-00"
                    :loading="false"
                >
                    <x-slot name="iconTrailing">
                        <flux:icon.loading wire:loading wire:target="cnpj" variant="mini" />
                    </x-slot>
                </flux:input>

                @if ($lookupMessage)
                    <flux:callout :variant="$lookupVariant" icon="information-circle" :heading="$lookupMessage" />
                @endif

                <div wire:loading.class="opacity-50 pointer-events-none" wire:target="cnpj" class="grid gap-4 sm:grid-cols-2 transition-opacity duration-200">
                    <flux:input wire:model="razao_social" :label="__('Razão social')" required />
                    <flux:input wire:model="nome_fantasia" :label="__('Nome fantasia')" />
                </div>

                <flux:select wire:model.live="uf" :label="__('UF')" :placeholder="__('Selecione')" required>
                    @foreach (Uf::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->value }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:separator />

        {{-- Busca de notas --}}
        <flux:fieldset>
            <flux:legend>{{ __('Busca de notas') }}</flux:legend>

            <div class="space-y-4">
                <flux:radio.group wire:model.live="modo_busca" :label="__('Como buscar as notas desta empresa?')" variant="cards" class="flex-col">
                    @foreach (ModoBusca::cases() as $modo)
                        <flux:radio :value="$modo->value" :label="$modo->label()" :description="$modo->resumo()" />
                    @endforeach
                </flux:radio.group>

                @if ($modo_busca === ModoBusca::ContadorSc->value)
                    <flux:callout icon="information-circle" color="violet">
                        <flux:callout.heading>{{ __('Antes de usar, confira:') }}</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="list-disc space-y-1 ps-5">
                                @foreach (ModoBusca::ContadorSc->limitacoes() as $limitacao)
                                    <li>{{ $limitacao }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                        @unless ($this->certificadoEscritorio)
                            <x-slot name="actions">
                                <flux:button size="sm" :href="route('companies.index')" wire:navigate>{{ __('Enviar certificado do escritório') }}</flux:button>
                            </x-slot>
                        @endunless
                    </flux:callout>
                @else
                    <flux:radio.group wire:model="ambiente" :label="__('Ambiente SEFAZ')">
                        @foreach (Ambiente::cases() as $option)
                            <flux:radio :value="$option->value" :label="$option->label()" />
                        @endforeach
                    </flux:radio.group>
                @endif
            </div>
        </flux:fieldset>

        <flux:separator />

        {{-- Sincronização --}}
        <flux:fieldset>
            <flux:legend>{{ __('Sincronização') }}</flux:legend>

            <div class="space-y-4">
                <flux:switch wire:model="ativo" :label="__('Sincronização ativa')" :description="__('Busca novas notas periodicamente quando ativada.')" />

                @if ($modo_busca === ModoBusca::CertificadoEmpresa->value)
                    <flux:switch wire:model="manifestacao_automatica" :label="__('Enviar Ciência da Operação automaticamente')"
                        :description="__('Ao receber só o resumo da NF-e, envia o evento 210210 para liberar o XML completo.')" />

                    <flux:switch wire:model="buscar_cte" :label="__('Buscar CT-e (conhecimentos de transporte)')"
                        :description="__('Além das NF-e, busca os CT-e em que a empresa aparece. Costuma trazer muito volume de frete.')" />
                @endif
            </div>
        </flux:fieldset>

        <flux:separator />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">
                <flux:icon.loading wire:loading wire:target="save" variant="mini" class="mr-1" />
                {{ __('Salvar') }}
            </flux:button>
            <flux:button :href="$company ? route('companies.show', $company) : route('companies.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
        </div>
    </form>
</section>
