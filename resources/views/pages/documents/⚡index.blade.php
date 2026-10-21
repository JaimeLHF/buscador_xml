<?php

use App\Enums\ExportStatus;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use App\Jobs\BuildXmlExport;
use App\Queries\DocumentFilter;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Documentos')] class extends Component {
    use WithPagination;

    #[Url] public string $company_id = '';
    #[Url] public string $de = '';
    #[Url] public string $ate = '';
    #[Url] public string $emitente = '';
    #[Url] public string $valor_min = '';
    #[Url] public string $valor_max = '';
    #[Url] public string $situacao = '';
    #[Url] public string $xml = '';
    #[Url] public string $manifestacao = '';
    #[Url] public string $chave = '';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function limpar(): void
    {
        $this->reset();
        $this->resetPage();
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return $this->only(['company_id', 'de', 'ate', 'emitente', 'valor_min', 'valor_max', 'situacao', 'xml', 'manifestacao', 'chave']);
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization->companies()->orderBy('razao_social')->get(['id', 'razao_social', 'cnpj']);
    }

    #[Computed]
    public function documents()
    {
        return DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())
            ->with('company:id,razao_social')
            ->orderByDesc('data_emissao')
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function exportar(): void
    {
        $key = 'export:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            Flux::toast(variant: 'warning', text: __('Muitas exportações seguidas. Aguarde alguns minutos.'));

            return;
        }

        RateLimiter::hit($key, 300);

        $export = Auth::user()->currentOrganization->exports()->create([
            'user_id' => Auth::id(),
            'filters' => $this->filters(),
            'status' => ExportStatus::Pending,
        ]);

        BuildXmlExport::dispatch($export);

        $this->redirectRoute('exports.index', navigate: true);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Documentos') }}</flux:heading>

        <flux:button icon="archive-box-arrow-down" wire:click="exportar" wire:confirm="{{ __('Gerar um ZIP com os XMLs de todos os documentos filtrados?') }}">
            {{ __('Exportar XMLs (ZIP)') }}
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="company_id" :label="__('Empresa')">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach ($this->companies as $c)
                <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="de" :label="__('Emissão de')" />
        <flux:input type="date" wire:model.live="ate" :label="__('Emissão até')" />
        <flux:input wire:model.live.debounce.400ms="emitente" :label="__('Emitente (nome ou CNPJ)')" />
        <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="valor_min" :label="__('Valor mínimo')" />
        <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="valor_max" :label="__('Valor máximo')" />
        <flux:select wire:model.live="situacao" :label="__('Situação')">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach (Situacao::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="xml" :label="__('XML')">
            <flux:select.option value="">{{ __('Todos') }}</flux:select.option>
            <flux:select.option value="completo">{{ __('Com XML completo') }}</flux:select.option>
            <flux:select.option value="resumo">{{ __('Somente resumo') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="manifestacao" :label="__('Manifestação')">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            <flux:select.option value="nenhuma">{{ __('Sem manifestação') }}</flux:select.option>
            @foreach (ManifestacaoStatus::cases() as $m)
                <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="chave" :label="__('Chave de acesso')" class="font-mono sm:col-span-2" />
        <div class="flex items-end">
            <flux:button variant="ghost" wire:click="limpar">{{ __('Limpar filtros') }}</flux:button>
        </div>
    </div>

    <flux:table :paginate="$this->documents">
        <flux:table.columns>
            <flux:table.column>{{ __('Emissão') }}</flux:table.column>
            <flux:table.column>{{ __('Emitente') }}</flux:table.column>
            <flux:table.column>{{ __('Destinatário') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Valor') }}</flux:table.column>
            <flux:table.column>{{ __('Situação') }}</flux:table.column>
            <flux:table.column>{{ __('Manifestação') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->documents as $doc)
                <flux:table.row :key="$doc->id">
                    <flux:table.cell class="whitespace-nowrap">{{ Fmt::date($doc->data_emissao) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:link :href="route('documents.show', $doc)" wire:navigate>{{ $doc->emitente_nome ?? $doc->chave }}</flux:link>
                        <flux:text size="sm" class="font-mono">{{ Fmt::cnpj($doc->emitente_cnpj) }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $doc->company->razao_social }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums whitespace-nowrap">{{ Fmt::money($doc->valor_total) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($doc->situacao) { Situacao::Autorizada => 'green', Situacao::Cancelada => 'red', default => 'amber' }">{{ $doc->situacao->label() }}</flux:badge>
                        @unless ($doc->tem_xml_completo)
                            <flux:badge size="sm" color="zinc">{{ __('resumo') }}</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell>{{ $doc->manifestacao_status?->label() ?? '—' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">{{ __('Nenhum documento encontrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
