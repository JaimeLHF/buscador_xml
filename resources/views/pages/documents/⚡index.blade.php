<?php

use App\Enums\ExportStatus;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use App\Jobs\BuildXmlExport;
use App\Jobs\SendConfirmacaoOperacao;
use App\Models\DfeDocument;
use App\Queries\DocumentFilter;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    /** @var list<int> */
    public array $selecionados = [];

    /** Teto por envio: cada Confirmação é um evento assinado e enviado individualmente à SEFAZ. */
    private const LOTE_MAXIMO = 500;

    public function updated(string $property): void
    {
        if ($property !== 'selecionados' && ! str_starts_with($property, 'selecionados.')) {
            $this->resetPage();
        }
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

    #[Computed]
    public function podeManifestar(): bool
    {
        return (bool) Auth::user()->roleIn(Auth::user()->current_organization_id)?->canManage();
    }

    /** @return list<int> IDs da página atual que aceitam a Confirmação em lote. */
    #[Computed]
    public function confirmaveisNaPagina(): array
    {
        if (! $this->podeManifestar) {
            return [];
        }

        return DfeDocument::query()->confirmaveisEmLote()
            ->whereIn('id', $this->documents->pluck('id'))
            ->pluck('id')->all();
    }

    #[Computed]
    public function totalConfirmaveis(): int
    {
        return $this->podeManifestar ? DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())->confirmaveisEmLote()->count() : 0;
    }

    public function selecionarFiltrados(): void
    {
        $this->selecionados = DocumentFilter::apply(Auth::user()->currentOrganization, $this->filters())
            ->confirmaveisEmLote()
            ->orderByDesc('data_emissao')
            ->limit(self::LOTE_MAXIMO)
            ->pluck('id')->all();
    }

    public function limparSelecao(): void
    {
        $this->selecionados = [];
    }

    public function confirmarLote(): void
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $this->selecionados))), 0, self::LOTE_MAXIMO);

        // Revalida tudo no servidor: a seleção vem do navegador.
        $documents = DocumentFilter::apply(Auth::user()->currentOrganization, [])
            ->confirmaveisEmLote()
            ->whereIn('id', $ids)
            ->with('company')
            ->get()
            ->filter(fn (DfeDocument $doc) => Gate::allows('manifest', $doc))
            ->values();

        foreach ($documents as $i => $doc) {
            $doc->update(['manifestacao_status' => ManifestacaoStatus::ConfirmacaoPendente]);
            SendConfirmacaoOperacao::dispatch($doc)->delay(now()->addSeconds($i * 2));
        }

        $this->selecionados = [];
        unset($this->documents, $this->confirmaveisNaPagina, $this->totalConfirmaveis);
        Flux::modal('confirmar-lote')->close();

        $documents->isEmpty()
            ? Flux::toast(variant: 'warning', text: __('Nenhuma das notas selecionadas pode receber a Confirmação.'))
            : Flux::toast(variant: 'success', text: __(':n Confirmações enviadas para a fila. O XML completo chega em seguida.', ['n' => $documents->count()]));
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

    @if ($this->podeManifestar && $this->totalConfirmaveis > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <flux:text>
                {{ trans_choice(':count nota selecionada|:count notas selecionadas', count($selecionados), ['count' => count($selecionados)]) }}
                · {{ __(':n filtradas podem receber a Confirmação', ['n' => $this->totalConfirmaveis]) }}
            </flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="ghost" wire:click="selecionarFiltrados">{{ __('Selecionar todas as filtradas') }}</flux:button>
                @if ($selecionados)
                    <flux:button size="sm" variant="ghost" wire:click="limparSelecao">{{ __('Limpar seleção') }}</flux:button>
                    <flux:modal.trigger name="confirmar-lote">
                        <flux:button size="sm" variant="primary" icon="check-badge">{{ __('Confirmar operação') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>
    @endif

    <flux:table :paginate="$this->documents">
        <flux:table.columns>
            @if ($this->podeManifestar)
                <flux:table.column class="w-8"></flux:table.column>
            @endif
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
                    @if ($this->podeManifestar)
                        <flux:table.cell>
                            @if (in_array($doc->id, $this->confirmaveisNaPagina, true))
                                <flux:checkbox wire:model.live="selecionados" :value="$doc->id" :aria-label="__('Selecionar nota')" />
                            @endif
                        </flux:table.cell>
                    @endif
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
                    <flux:table.cell colspan="7" class="text-center">{{ __('Nenhum documento encontrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirmar-lote" class="md:w-[32rem]">
        <form wire:submit="confirmarLote" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirmar operação de :count notas', ['count' => count($selecionados)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('A Confirmação declara à SEFAZ que a compra aconteceu e a mercadoria foi recebida. Cada evento é assinado com o certificado da empresa e não pode ser desfeito.') }}</flux:text>
                <flux:text class="mt-2">{{ __('Envie apenas notas que a empresa reconhece. Para notas desconhecidas, use a tela da nota.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Confirmar e enviar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
