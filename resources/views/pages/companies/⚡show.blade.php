<?php

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Empresa')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company->load(['activeCertificate', 'syncStates']);
    }

    #[Livewire\Attributes\Computed]
    public function recentDocuments()
    {
        return $this->company->documents()->latest('data_emissao')->limit(20)->get();
    }
}; ?>

<section class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $company->razao_social }}</flux:heading>
            <flux:text class="font-mono">{{ $company->cnpjValue()->formatted() }} · {{ $company->uf->value }} · {{ $company->ambiente->label() }}</flux:text>
        </div>

        @can('update', $company)
            <flux:button icon="pencil-square" :href="route('companies.edit', $company)" wire:navigate>{{ __('Editar') }}</flux:button>
        @endcan
    </div>

    @if (session('status') === 'certificate-saved')
        <flux:callout variant="success" icon="check-circle" :heading="__('Certificado salvo e validado.')" />
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Certificado digital A1') }}</flux:heading>

            @if ($cert = $company->activeCertificate)
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Titular') }}</dt><dd>{{ $cert->titular }}</dd>
                    <dt class="text-zinc-500">{{ __('CNPJ') }}</dt><dd class="font-mono">{{ App\Support\Cnpj::from($cert->cnpj_certificado)->formatted() }}</dd>
                    <dt class="text-zinc-500">{{ __('Validade') }}</dt><dd>{{ $cert->valid_from->format('d/m/Y') }} – {{ $cert->valid_to->format('d/m/Y') }}</dd>
                    <dt class="text-zinc-500">{{ __('Situação') }}</dt><dd><x-certificate-badge :certificate="$cert" /></dd>
                </dl>
            @else
                <flux:text>{{ __('Nenhum certificado enviado. A sincronização só começa após o envio.') }}</flux:text>
            @endif

            @can('manageCertificate', $company)
                <flux:separator />

                <form method="POST" action="{{ route('companies.certificates.store', $company) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <flux:input type="file" name="pfx" accept=".pfx,.p12" :label="$cert ? __('Trocar certificado (.pfx)') : __('Arquivo do certificado (.pfx)')" required />
                    <flux:input type="password" name="password" :label="__('Senha do certificado')" required autocomplete="off" viewable />

                    @error('pfx') <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror

                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Enviar certificado') }}</flux:button>
                </form>
            @endcan
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Sincronização') }}</flux:heading>

            @foreach ($company->syncStates as $state)
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Documento') }}</dt><dd>{{ $state->tipo->label() }}</dd>
                    <dt class="text-zinc-500">{{ __('Último NSU') }}</dt><dd class="font-mono">{{ $state->ult_nsu }}</dd>
                    <dt class="text-zinc-500">{{ __('Última consulta') }}</dt><dd>{{ $state->last_checked_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</dd>
                    <dt class="text-zinc-500">{{ __('Próxima consulta') }}</dt><dd>{{ $state->next_check_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? __('Assim que houver certificado') }}</dd>
                    <dt class="text-zinc-500">{{ __('Último retorno') }}</dt><dd>{{ $state->last_cstat ? $state->last_cstat.' – '.$state->last_xmotivo : '—' }}</dd>
                </dl>
            @endforeach
        </flux:card>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Últimos documentos recebidos') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Emissão') }}</flux:table.column>
                <flux:table.column>{{ __('Emitente') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Valor') }}</flux:table.column>
                <flux:table.column>{{ __('Situação') }}</flux:table.column>
                <flux:table.column>{{ __('Manifestação') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->recentDocuments as $doc)
                    <flux:table.row :key="$doc->id">
                        <flux:table.cell>{{ $doc->data_emissao?->timezone('America/Sao_Paulo')->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('documents.show', $doc)" wire:navigate>{{ $doc->emitente_nome }}</flux:link></flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((float) $doc->valor_total, 2, ',', '.') }}</flux:table.cell>
                        <flux:table.cell>{{ $doc->situacao->label() }}{{ $doc->tem_xml_completo ? '' : ' · '.__('resumo') }}</flux:table.cell>
                        <flux:table.cell>{{ $doc->manifestacao_status?->label() ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('Nenhum documento recebido ainda.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
