<?php

use App\Enums\TipoEvento;
use App\Models\DfeDocument;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use App\Support\Cnpj;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documento')] class extends Component {
    public DfeDocument $document;

    public ?string $acao = null;
    public string $justificativa = '';

    public function mount(DfeDocument $document): void
    {
        Gate::authorize('view', $document);

        $this->document = $document;
    }

    #[Computed]
    public function eventos()
    {
        return $this->document->company->events()->where('chave', $this->document->chave)->orderBy('data_evento')->get();
    }

    /** @return list<TipoEvento> */
    #[Computed]
    public function manifestacoes(): array
    {
        return Gate::allows('manifest', $this->document) ? app(ManifestacaoService::class)->disponiveis($this->document) : [];
    }

    public function confirmar(string $tpEvento): void
    {
        $this->acao = TipoEvento::from($tpEvento)->value;
        $this->justificativa = '';
        $this->resetErrorBag();

        Flux::modal('manifestar')->show();
    }

    public function manifestar(ManifestacaoService $service): void
    {
        Gate::authorize('manifest', $this->document);

        $tipo = TipoEvento::from((string) $this->acao);

        try {
            $service->manifestar($this->document, $tipo, $this->justificativa);
        } catch (ManifestacaoException $e) {
            $this->addError('justificativa', $e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::error('Falha ao manifestar', ['document_id' => $this->document->id, 'exception' => $e::class]);
            $this->addError('justificativa', __('Não foi possível comunicar com a SEFAZ. Tente novamente em alguns minutos.'));

            return;
        }

        $this->document->refresh();
        unset($this->eventos, $this->manifestacoes);

        Flux::modal('manifestar')->close();
        Flux::toast(variant: 'success', text: __(':evento registrada.', ['evento' => $tipo->label()]));
    }
}; ?>

<section class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:link :href="route('documents.index')" wire:navigate class="text-sm">← {{ __('Notas recebidas') }}</flux:link>
            <flux:heading size="xl" level="1">{{ $document->emitente_nome ?? __('NF-e') }}</flux:heading>
            <flux:text class="font-mono break-all">{{ $document->chave }}</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="arrow-down-tray" :href="route('documents.xml', $document)">{{ __('XML') }}</flux:button>
            @if ($document->tem_xml_completo)
                <flux:button size="sm" icon="document" :href="route('documents.danfe', $document)" target="_blank">{{ __('DANFE') }}</flux:button>
            @endif
            <flux:badge :color="match ($document->situacao) { App\Enums\Situacao::Autorizada => 'green', App\Enums\Situacao::Cancelada => 'red', default => 'amber' }">
                {{ $document->situacao->label() }}
            </flux:badge>
            <flux:badge :color="$document->tem_xml_completo ? 'green' : 'zinc'">
                {{ $document->tem_xml_completo ? __('XML completo') : __('Somente resumo') }}
            </flux:badge>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-zinc-500">{{ __('Emitente') }}</dt>
                <dd>{{ $document->emitente_nome }}<br><span class="font-mono">{{ $document->emitente_cnpj && Cnpj::isValid($document->emitente_cnpj) ? Cnpj::from($document->emitente_cnpj)->formatted() : $document->emitente_cnpj }}</span></dd>
                <dt class="text-zinc-500">{{ __('Emissão') }}</dt>
                <dd>{{ $document->data_emissao?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</dd>
                <dt class="text-zinc-500">{{ __('Valor') }}</dt>
                <dd>R$ {{ number_format((float) $document->valor_total, 2, ',', '.') }}</dd>
                <dt class="text-zinc-500">{{ __('NSU') }}</dt>
                <dd class="font-mono">{{ $document->nsu }}</dd>
                <dt class="text-zinc-500">{{ __('Manifestação') }}</dt>
                <dd>{{ $document->manifestacao_status?->label() ?? __('Nenhuma') }}</dd>
            </dl>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Manifestação do destinatário') }}</flux:heading>

            @forelse ($this->manifestacoes as $tipo)
                <flux:button wire:click="confirmar('{{ $tipo->value }}')" class="w-full" :variant="$tipo === App\Enums\TipoEvento::CienciaOperacao ? 'primary' : 'filled'">
                    {{ $tipo->label() }}
                </flux:button>
            @empty
                <flux:text>{{ auth()->user()->can('manifest', $document) ? __('Nenhuma manifestação disponível para este documento.') : __('Somente donos e administradores da organização podem manifestar.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Eventos') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Evento') }}</flux:table.column>
                <flux:table.column>{{ __('Data') }}</flux:table.column>
                <flux:table.column>{{ __('Protocolo') }}</flux:table.column>
                <flux:table.column>{{ __('Origem') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->eventos as $evento)
                    <flux:table.row :key="$evento->id">
                        <flux:table.cell>{{ App\Enums\TipoEvento::tryFrom($evento->tp_evento)?->label() ?? $evento->descricao ?? $evento->tp_evento }}</flux:table.cell>
                        <flux:table.cell>{{ $evento->data_evento?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</flux:table.cell>
                        <flux:table.cell class="font-mono">{{ $evento->protocolo }}</flux:table.cell>
                        <flux:table.cell>{{ $evento->direcao === 'enviado' ? __('Enviado por nós') : __('Recebido') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('Nenhum evento.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="manifestar" class="md:w-[28rem]">
        <form wire:submit="manifestar" class="space-y-6">
            @if ($acao)
                <div>
                    <flux:heading size="lg" level="2">{{ App\Enums\TipoEvento::from($acao)->label() }}</flux:heading>
                    <flux:text class="mt-2">{{ __('O evento será assinado com o certificado da empresa e enviado à SEFAZ. Não é possível desfazer.') }}</flux:text>
                </div>

                @if (App\Enums\TipoEvento::from($acao)->exigeJustificativa())
                    <flux:textarea wire:model="justificativa" :label="__('Justificativa')" rows="3" required minlength="15" maxlength="255" />
                @else
                    <flux:error name="justificativa" />
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Confirmar e enviar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
