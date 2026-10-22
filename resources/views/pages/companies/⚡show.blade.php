<?php

use App\Models\Company;
use App\Services\Sefaz\SyncProgress;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Empresa')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company->load(['activeCertificate', 'syncStates', 'organization.officeCertificate']);
    }

    /** Por que a busca pela SEF-SC ainda não roda (modo contador), em linguagem simples. */
    #[Livewire\Attributes\Computed]
    public function impedimento(): ?string
    {
        return $this->company->buscaPeloContador() ? App\Services\SefSc\SefScDistribuicaoService::impedimento($this->company) : null;
    }

    #[Livewire\Attributes\Computed]
    public function syncStatus(): ?array
    {
        return SyncProgress::get($this->company->id);
    }

    #[Livewire\Attributes\Computed]
    public function recentDocuments()
    {
        return $this->company->documents()->latest('data_emissao')->limit(20)->get();
    }
}; ?>

@php
    $sync = $this->syncStatus;
    $syncActive = $sync && in_array($sync['status'] ?? '', ['queued', 'running']);
    $syncDone = $sync && ($sync['status'] ?? '') === 'done';
    $pollInterval = $syncActive ? 'wire:poll.2s' : ($syncDone ? 'wire:poll.5s' : 'wire:poll.30s');
@endphp
<section class="w-full space-y-8" {{ $pollInterval }}>
    @if ($sync)
        @php
            $status = $sync['status'] ?? 'queued';
            $isActive = in_array($status, ['queued', 'running']);
            $isDone = $status === 'done';
            $outcome = $sync['outcome'] ?? null;
            $page = $sync['page'] ?? 0;
            $docs = $sync['docs'] ?? 0;
            $ultNsu = $sync['ult_nsu'] ?? null;
            $maxNsu = $sync['max_nsu'] ?? null;
            $percent = ($ultNsu && $maxNsu && (int) $maxNsu > 0) ? min(100, (int) round((int) $ultNsu / (int) $maxNsu * 100)) : null;

            if ($isDone) {
                $color = match($outcome) {
                    'up_to_date' => 'green',
                    'blocked', 'failed' => 'red',
                    default => 'zinc',
                };
                $icon = match($outcome) {
                    'up_to_date' => 'check-circle',
                    'blocked', 'failed' => 'x-circle',
                    'not_due', 'locked' => 'clock',
                    default => 'information-circle',
                };
                $label = match($outcome) {
                    'up_to_date' => __('Sincronização concluída'),
                    'blocked' => __('Bloqueado pela SEFAZ (consumo indevido)'),
                    'failed' => $sync['message'] ?? __('Falha na sincronização'),
                    'not_due' => __('Consulta ainda não liberada'),
                    'locked' => __('Outra sincronização já está rodando'),
                    default => __('Sincronização finalizada'),
                };
            }
        @endphp

        @if ($isActive)
            <flux:callout icon="arrow-path" color="blue">
                <div class="w-full space-y-2">
                    <div class="flex items-center justify-between">
                        <flux:text class="font-medium">
                            @if ($status === 'queued')
                                {{ $sync['message'] ?? __('Na fila…') }}
                            @else
                                {{ __('Sincronizando…') }}
                                @if ($page > 0)
                                    <span class="text-zinc-500 font-normal">{{ __('Página :n', ['n' => $page]) }}</span>
                                @endif
                            @endif
                        </flux:text>
                        @if ($docs > 0)
                            <flux:badge color="blue" size="sm">{{ trans_choice(':n documento|:n documentos', $docs, ['n' => number_format($docs, 0, ',', '.')]) }}</flux:badge>
                        @endif
                    </div>

                    @if ($percent !== null)
                        <div class="w-full rounded-full bg-zinc-200 dark:bg-zinc-700 h-2.5">
                            <div class="h-2.5 rounded-full bg-blue-500 transition-all duration-500" style="width: {{ $percent }}%"></div>
                        </div>
                        <flux:text size="sm" class="tabular-nums">{{ $percent }}% · NSU {{ number_format((int) $ultNsu, 0, ',', '.') }} {{ __('de') }} {{ number_format((int) $maxNsu, 0, ',', '.') }}</flux:text>
                    @else
                        <div class="w-full rounded-full bg-zinc-200 dark:bg-zinc-700 h-2.5 overflow-hidden">
                            <div class="h-2.5 rounded-full bg-blue-500 w-1/3 animate-[indeterminate_1.5s_ease-in-out_infinite]"></div>
                        </div>
                        @if ($status === 'running' && $page > 0)
                            <flux:text size="sm" class="tabular-nums">{{ __('Página :n · :d documentos até agora', ['n' => $page, 'd' => number_format($docs, 0, ',', '.')]) }}</flux:text>
                        @endif
                    @endif
                </div>
            </flux:callout>
        @elseif ($isDone)
            <flux:callout :icon="$icon" :color="$color">
                <div class="flex items-center justify-between w-full">
                    <flux:text class="font-medium">{{ $label }}</flux:text>
                    @if ($docs > 0)
                        <flux:badge :color="$color" size="sm">{{ trans_choice(':n documento recebido|:n documentos recebidos', $docs, ['n' => number_format($docs, 0, ',', '.')]) }}</flux:badge>
                    @endif
                </div>
            </flux:callout>
        @endif
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $company->razao_social }}</flux:heading>
            <flux:text class="font-mono">{{ $company->cnpjValue()->formatted() }} · {{ $company->uf->value }} · {{ $company->ambiente->label() }}</flux:text>
            <flux:badge size="sm" class="mt-2" :color="$company->modo_busca->badgeColor()">{{ __('Busca: :m', ['m' => $company->modo_busca->label()]) }}</flux:badge>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" icon="clock" :href="route('sefaz-log', ['company_id' => $company->id])" wire:navigate>{{ __('Histórico de buscas') }}</flux:button>
            @php($syncing = $sync && in_array($sync['status'] ?? '', ['queued', 'running']))
            <form method="POST" action="{{ route('companies.sync', $company) }}">
                @csrf
                <flux:button type="submit" icon="arrow-path" :disabled="$syncing">{{ $syncing ? __('Sincronizando…') : __('Sincronizar agora') }}</flux:button>
            </form>

            @can('update', $company)
                <flux:button icon="pencil-square" :href="route('companies.edit', $company)" wire:navigate>{{ __('Editar') }}</flux:button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($company->buscaPeloContador())
            <flux:card class="space-y-4">
                <flux:heading size="lg" level="2">{{ __('Certificado do contador') }}</flux:heading>
                <flux:text>{{ __('Esta empresa não usa certificado próprio: as notas vêm da SEF-SC com o certificado do escritório, que vale para todas as empresas de SC.') }}</flux:text>

                @if ($escritorio = $company->organization->officeCertificate)
                    <x-campos :itens="[
                        __('Titular') => $escritorio->titular,
                        strlen($escritorio->cnpj_certificado) === 11 ? __('CPF') : __('CNPJ') => $escritorio->documentoFormatado(),
                        __('Validade') => $escritorio->valid_from->format('d/m/Y').' – '.$escritorio->valid_to->format('d/m/Y'),
                    ]" />
                @endif

                @if ($this->impedimento)
                    <flux:callout icon="exclamation-triangle" color="amber" :heading="$this->impedimento" />
                @endif

                <flux:button size="sm" :href="route('companies.index')" wire:navigate>{{ __('Gerenciar certificado do escritório') }}</flux:button>

                {{-- Opcional: com o certificado da própria empresa, a manifestação (Ciência, Confirmação) também fica disponível. --}}
                <details class="text-sm">
                    <summary class="cursor-pointer font-medium">{{ __('Certificado da própria empresa (opcional, só para manifestar)') }}</summary>
                    <div class="mt-3 space-y-4"><x-company-certificate :company="$company" /></div>
                </details>
            </flux:card>
        @else
            <flux:card class="space-y-4">
                <flux:heading size="lg" level="2">{{ __('Certificado digital A1') }}</flux:heading>
                <x-company-certificate :company="$company" />
            </flux:card>
        @endif

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Sincronização') }}</flux:heading>

            @if ($company->buscaPeloContador())
                @php($sc = $company->sefScState())
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Origem') }}</dt><dd>{{ __('SEF-SC, NF-e emitidas e recebidas') }}</dd>
                    <dt class="text-zinc-500">{{ __('Último NSU') }}</dt><dd class="font-mono">{{ $sc->ult_nsu }}</dd>
                    <dt class="text-zinc-500">{{ __('Última consulta') }}</dt><dd>{{ $sc->last_checked_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</dd>
                    <dt class="text-zinc-500">{{ __('Próxima consulta') }}</dt><dd>{{ $sc->next_check_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? __('Assim que tudo estiver configurado') }}</dd>
                    <dt class="text-zinc-500">{{ __('Último retorno') }}</dt><dd>{{ $sc->last_cstat ? $sc->last_cstat.' – '.$sc->last_xmotivo : '—' }}</dd>
                    @if ($sc->last_error)
                        <dt class="text-zinc-500">{{ __('Erro') }}</dt><dd class="text-red-600 dark:text-red-400">{{ $sc->last_error }}</dd>
                    @endif
                </dl>
                <flux:text size="sm">{{ __('Depois de receber todas as notas, a SEF-SC só aceita nova busca 12 horas depois.') }}</flux:text>
            @else
            @foreach ($company->syncStates as $state)
                @if ($state->last_cstat === '656' && $state->next_check_at?->isFuture())
                    <flux:callout icon="shield-exclamation" color="amber">
                        <flux:callout.heading>{{ __(':tipo bloqueado pela SEFAZ (consumo indevido)', ['tipo' => $state->tipo->label()]) }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('A SEFAZ limitou as consultas de :tipo deste CNPJ. Isso é normal na primeira sincronização. O sistema tentará novamente automaticamente às :hora.', [
                                'tipo' => $state->tipo->label(),
                                'hora' => $state->next_check_at->timezone('America/Sao_Paulo')->format('H:i'),
                            ]) }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Documento') }}</dt><dd>{{ $state->tipo->label() }}</dd>
                    <dt class="text-zinc-500">{{ __('Último NSU') }}</dt><dd class="font-mono">{{ $state->ult_nsu }}</dd>
                    <dt class="text-zinc-500">{{ __('Última consulta') }}</dt><dd>{{ $state->last_checked_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</dd>
                    <dt class="text-zinc-500">{{ __('Próxima consulta') }}</dt><dd>{{ $state->next_check_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? __('Assim que houver certificado') }}</dd>
                    <dt class="text-zinc-500">{{ __('Último retorno') }}</dt><dd>{{ $state->last_cstat ? $state->last_cstat.' – '.$state->last_xmotivo : '—' }}</dd>
                    @if ($state->last_error)
                        <dt class="text-zinc-500">{{ __('Erro') }}</dt><dd class="text-red-600 dark:text-red-400">{{ $state->last_error }}</dd>
                    @endif
                    @if ($state->consecutive_656 > 0)
                        <dt class="text-zinc-500">{{ __('Consumo indevido') }}</dt><dd class="text-red-600 dark:text-red-400">{{ trans_choice(':n bloqueio seguido|:n bloqueios seguidos', $state->consecutive_656, ['n' => $state->consecutive_656]) }}</dd>
                    @endif
                </dl>
            @endforeach
            @endif
        </flux:card>
    </div>

    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" level="2">{{ __('Últimos documentos recebidos') }}</flux:heading>
            <flux:link :href="route('documents.index', ['company_id' => $company->id])" wire:navigate>{{ __('Ver notas desta empresa') }}</flux:link>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
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
                        <flux:table.cell align="end" class="tabular-nums whitespace-nowrap">{{ App\Support\Fmt::money($doc->valor_total) }}</flux:table.cell>
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
    </div>
</section>
