<?php

use App\Enums\TipoEvento;
use App\Models\DfeDocument;
use App\Services\Documents\NfeReader;
use App\Services\Sefaz\XmlStorage;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use App\Support\Cnpj;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nota fiscal')] class extends Component {
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

    /** Conteúdo do XML (completo ou resumo) organizado para exibição; null se o arquivo faltar ou não puder ser lido. */
    #[Computed]
    public function nfe(): ?array
    {
        $path = $this->document->xml_path ?? $this->document->resumo_xml_path;
        $xml = $path ? app(XmlStorage::class)->get($path) : null;

        try {
            return $xml ? (new NfeReader($xml))->read() : null;
        } catch (\RuntimeException) {
            return null;
        }
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

@use('App\Support\Fmt')
@php
    $nfe = $this->nfe;
    $completo = ($nfe['tipo'] ?? null) === 'completo';
    $ide = $completo ? $nfe['ide'] : [];
    $moeda = fn (?string $v) => $v === null ? null : Fmt::money($v);
    $formatarParte = fn (?array $p) => $p ? collect($p)->map(fn ($v, $k) => in_array($k, ['CNPJ', 'CPF'], true) ? Fmt::cnpj($v) : $v)->all() : [];
    $abas = array_filter([
        'geral' => __('Geral'),
        'produtos' => $completo ? __('Produtos (:n)', ['n' => count($nfe['itens'])]) : null,
        'impostos' => $completo ? __('Impostos e totais') : null,
        'transporte' => $completo ? __('Transporte e pagamento') : null,
        'xml' => $nfe ? __('XML') : null,
    ]);
@endphp

<section class="w-full space-y-6">
    {{-- Cabeçalho: quem emitiu, qual nota e as ações de arquivo. --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-1">
            <flux:link :href="route('documents.index')" wire:navigate class="text-sm">← {{ __('Notas recebidas') }}</flux:link>
            <flux:heading size="xl" level="1">{{ $document->emitente_nome ?? __('NF-e') }}</flux:heading>
            <flux:text>
                @if ($completo)
                    {{ __('NF-e nº :n, série :s', ['n' => $ide['Número'] ?? '—', 's' => $ide['Série'] ?? '—']) }} ·
                @endif
                {{ __('emitida em :data', ['data' => Fmt::dateTime($document->data_emissao)]) }}
            </flux:text>
            <flux:text class="font-mono text-xs break-all">{{ $document->chave }}</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="arrow-down-tray" :href="route('documents.xml', $document)">{{ __('XML') }}</flux:button>
            @if ($document->tem_xml_completo)
                <flux:button size="sm" icon="document-text" :href="route('documents.danfe', $document)" target="_blank">{{ __('DANFE') }}</flux:button>
            @endif
            <flux:badge :color="match ($document->situacao) { App\Enums\Situacao::Autorizada => 'green', App\Enums\Situacao::Cancelada => 'red', default => 'amber' }">
                {{ $document->situacao->label() }}
            </flux:badge>
        </div>
    </div>

    {{-- Faixa de números principais. --}}
    <dl class="grid grid-cols-2 gap-px border border-zinc-200 bg-zinc-200 md:grid-cols-3 xl:grid-cols-6 dark:border-zinc-700 dark:bg-zinc-700">
        @foreach (array_filter([
            __('Valor total') => Fmt::money($document->valor_total),
            __('Natureza da operação') => $ide['Natureza da operação'] ?? null,
            __('Tipo') => $ide['Tipo'] ?? ($nfe['resumo']['Tipo'] ?? null),
            __('Manifestação') => $document->manifestacao_status?->label() ?? __('Nenhuma'),
            __('Protocolo') => $nfe['protocolo']['Protocolo'] ?? ($nfe['resumo']['Protocolo'] ?? null),
        ]) as $rotulo => $valor)
            <div @class(['bg-white px-4 py-3 dark:bg-zinc-800', 'col-span-2' => $rotulo === __('Natureza da operação'), 'max-md:col-span-2' => in_array($rotulo, [__('Valor total'), __('Protocolo')], true)])>
                <dt class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $rotulo }}</dt>
                <dd class="mt-1 text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $valor }}</dd>
            </div>
        @endforeach
    </dl>

    @unless ($completo)
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.text>{{ __('A SEFAZ entregou só o resumo desta nota, por isso produtos, impostos e transporte ainda não aparecem. Eles chegam junto com o XML completo, depois da manifestação.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {{-- Dados do XML em abas. --}}
        <div x-data="{ aba: 'geral' }" class="min-w-0 border border-zinc-200 dark:border-zinc-700">
            <div role="tablist" class="flex overflow-x-auto border-b border-zinc-200 [scrollbar-width:none] dark:border-zinc-700">
                @foreach ($abas as $chave => $rotulo)
                    <button type="button" role="tab" x-on:click="aba = '{{ $chave }}'" x-bind:aria-selected="aba === '{{ $chave }}'"
                        class="shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                        x-bind:class="aba === '{{ $chave }}' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent'">
                        {{ $rotulo }}
                    </button>
                @endforeach
            </div>

            {{-- Geral --}}
            <div x-show="aba === 'geral'" role="tabpanel" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @if ($completo)
                    <div class="grid gap-6 p-4 md:grid-cols-2">
                        <div class="space-y-3">
                            <flux:heading level="2">{{ __('Emitente') }}</flux:heading>
                            <x-campos :itens="$formatarParte($nfe['emitente'])" />
                        </div>
                        <div class="space-y-3">
                            <flux:heading level="2">{{ __('Destinatário') }}</flux:heading>
                            <x-campos :itens="$formatarParte($nfe['destinatario'])" />
                        </div>
                    </div>
                    <div class="space-y-3 p-4">
                        <flux:heading level="2">{{ __('Identificação da nota') }}</flux:heading>
                        <x-campos class="md:grid-cols-[minmax(8rem,auto)_1fr_minmax(8rem,auto)_1fr]" :itens="collect($ide)->map(fn ($v, $k) => in_array($k, ['Emissão', 'Saída/entrada'], true) ? Fmt::xmlDate($v) : $v)->all()" />
                    </div>
                    @if ($nfe['protocolo'])
                        <div class="space-y-3 p-4">
                            <flux:heading level="2">{{ __('Autorização') }}</flux:heading>
                            <x-campos :itens="collect($nfe['protocolo'])->map(fn ($v, $k) => $k === 'Autorizada em' ? Fmt::xmlDate($v) : $v)->all()" />
                        </div>
                    @endif
                    @if ($nfe['adicionais'] || $nfe['observacoes'])
                        <div class="space-y-3 p-4">
                            <flux:heading level="2">{{ __('Informações adicionais') }}</flux:heading>
                            @foreach ($nfe['adicionais'] as $rotulo => $texto)
                                <div>
                                    <flux:text size="sm" class="text-zinc-500">{{ $rotulo }}</flux:text>
                                    <p class="mt-1 text-sm whitespace-pre-line text-zinc-900 dark:text-zinc-100">{{ $texto }}</p>
                                </div>
                            @endforeach
                            @if ($nfe['observacoes'])
                                <x-campos :itens="collect($nfe['observacoes'])->mapWithKeys(fn ($o) => [$o['campo'] => $o['texto']])->all()" />
                            @endif
                        </div>
                    @endif
                    @if ($nfe['autorizados'] || $nfe['responsavel'])
                        <div class="grid gap-6 p-4 md:grid-cols-2">
                            @if ($nfe['autorizados'])
                                <div class="space-y-3">
                                    <flux:heading level="2">{{ __('Autorizados a baixar o XML') }}</flux:heading>
                                    <p class="text-sm tabular-nums">{{ collect($nfe['autorizados'])->map(fn ($d) => Fmt::cnpj($d))->join(', ') }}</p>
                                </div>
                            @endif
                            @if ($nfe['responsavel'])
                                <div class="space-y-3">
                                    <flux:heading level="2">{{ __('Responsável técnico (sistema emissor)') }}</flux:heading>
                                    <x-campos :itens="$formatarParte($nfe['responsavel'])" />
                                </div>
                            @endif
                        </div>
                    @endif
                @elseif ($nfe)
                    <div class="p-4">
                        <x-campos :itens="collect($nfe['resumo'])->map(fn ($v, $k) => match ($k) { 'Emissão', 'Autorizada em' => Fmt::xmlDate($v), 'Valor total' => Fmt::money($v), 'CNPJ' => Fmt::cnpj($v), default => $v })->all()" />
                    </div>
                @else
                    <div class="p-4"><flux:text>{{ __('Não foi possível ler o XML desta nota. Use o botão XML para baixar o arquivo original.') }}</flux:text></div>
                @endif
            </div>

            @if ($completo)
                {{-- Produtos --}}
                <div x-show="aba === 'produtos'" x-cloak role="tabpanel" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-zinc-200 text-xs tracking-wide text-zinc-500 uppercase dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-2 text-start font-medium">#</th>
                                <th class="px-2 py-2 text-start font-medium">{{ __('Produto') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ __('NCM') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ __('CFOP') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Qtd.') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Unitário') }}</th>
                                <th class="px-4 py-2 text-end font-medium">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        @foreach ($nfe['itens'] as $item)
                            <tbody x-data="{ aberto: false }" class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/[0.03]" x-on:click="aberto = ! aberto">
                                    <td class="px-4 py-2 align-top tabular-nums text-zinc-500">{{ $item['n'] }}</td>
                                    <td class="px-2 py-2 align-top">
                                        <button type="button" class="text-start font-medium text-zinc-900 dark:text-white" x-bind:aria-expanded="aberto">{{ $item['descricao'] }}</button>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Cód. :c', ['c' => $item['codigo']]) }}@if ($item['ean']) · EAN {{ $item['ean'] }}@endif</div>
                                    </td>
                                    <td class="px-2 py-2 align-top tabular-nums">{{ $item['ncm'] }}</td>
                                    <td class="px-2 py-2 align-top tabular-nums">{{ $item['cfop'] }}</td>
                                    <td class="px-2 py-2 text-end align-top whitespace-nowrap tabular-nums">{{ Fmt::num($item['quantidade']) }} {{ $item['unidade'] }}</td>
                                    <td class="px-2 py-2 text-end align-top whitespace-nowrap tabular-nums">{{ 'R$ '.Fmt::num($item['unitario'], 4, 2) }}</td>
                                    <td class="px-4 py-2 text-end align-top font-medium whitespace-nowrap tabular-nums">{{ Fmt::money($item['total']) }}</td>
                                </tr>
                                <tr x-show="aberto" x-cloak class="bg-zinc-50 dark:bg-white/[0.02]">
                                    <td></td>
                                    <td colspan="6" class="px-2 pt-1 pb-3">
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <x-campos :itens="array_filter([__('CEST') => $item['cest'], __('Desconto') => $moeda($item['desconto']), __('Frete') => $moeda($item['frete']), __('Pedido') => $item['pedido'], __('Informação do item') => $item['info']])" />
                                            <div class="space-y-2">
                                                @foreach ($item['impostos'] as $imposto => $campos)
                                                    <div class="text-sm">
                                                        <span class="font-medium">{{ $imposto }}</span>
                                                        <span class="text-zinc-500 dark:text-zinc-400">
                                                            {{ collect($campos)->map(fn ($v, $k) => $k.' '.(in_array($k, ['Base', 'Valor', 'Base ST', 'Valor ST'], true) ? Fmt::money($v) : ($k === 'Alíquota' ? Fmt::num($v, 4).'%' : $v)))->join(' · ') }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        @endforeach
                    </table>
                    <flux:text size="sm" class="px-4 py-2">{{ __('Clique em um produto para ver impostos e detalhes do item.') }}</flux:text>
                </div>

                {{-- Impostos e totais --}}
                <div x-show="aba === 'impostos'" x-cloak role="tabpanel" class="p-4">
                    <dl class="grid gap-px border border-zinc-200 bg-zinc-200 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-700">
                        {{-- Valores zerados ficam de fora para os que importam aparecerem; o XML completo está na aba XML. --}}
                        @foreach (collect($nfe['totais'])->filter(fn ($v, $k) => (float) $v != 0 || in_array($k, ['Produtos', 'Total da nota'], true)) as $rotulo => $valor)
                            <div @class(['px-3 py-2', 'bg-white dark:bg-zinc-800' => $rotulo !== 'Total da nota', 'bg-zinc-100 dark:bg-zinc-700' => $rotulo === 'Total da nota'])>
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $rotulo }}</dt>
                                <dd @class(['tabular-nums', 'text-lg font-semibold' => $rotulo === 'Total da nota', 'font-medium' => $rotulo !== 'Total da nota'])>{{ Fmt::money($valor) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Transporte e pagamento --}}
                <div x-show="aba === 'transporte'" x-cloak role="tabpanel" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <div class="space-y-3 p-4">
                        <flux:heading level="2">{{ __('Transporte') }}</flux:heading>
                        <x-campos :itens="collect($nfe['transporte']['dados'] ?? [])->map(fn ($v, $k) => str_starts_with($k, 'CNPJ') ? Fmt::cnpj($v) : $v)->all()" />
                        @foreach ($nfe['transporte']['volumes'] ?? [] as $vol)
                            <x-campos :itens="collect($vol)->map(fn ($v, $k) => str_starts_with($k, 'Peso') || $k === 'Quantidade' ? Fmt::num($v, 3) : $v)->all()" />
                        @endforeach
                    </div>
                    @if ($nfe['cobranca'])
                        <div class="space-y-3 p-4">
                            <flux:heading level="2">{{ __('Cobrança') }}</flux:heading>
                            @if ($nfe['cobranca']['fatura'])
                                <x-campos :itens="collect($nfe['cobranca']['fatura'])->map(fn ($v, $k) => $k === 'Fatura' ? $v : Fmt::money($v))->all()" />
                            @endif
                            @if ($nfe['cobranca']['duplicatas'])
                                <table class="w-full max-w-md text-sm">
                                    <thead class="text-xs tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                                        <tr><th class="py-1 text-start font-medium">{{ __('Parcela') }}</th><th class="py-1 text-start font-medium">{{ __('Vencimento') }}</th><th class="py-1 text-end font-medium">{{ __('Valor') }}</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($nfe['cobranca']['duplicatas'] as $dup)
                                            <tr><td class="py-1 tabular-nums">{{ $dup['numero'] }}</td><td class="py-1 tabular-nums">{{ Fmt::xmlDate($dup['vencimento'], false) }}</td><td class="py-1 text-end tabular-nums">{{ Fmt::money($dup['valor']) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @endif
                    <div class="space-y-3 p-4">
                        <flux:heading level="2">{{ __('Pagamento') }}</flux:heading>
                        @forelse ($nfe['pagamentos'] as $pag)
                            <x-campos :itens="array_filter([__('Forma') => $pag['forma'].($pag['descricao'] ? ' – '.$pag['descricao'] : ''), __('Condição') => $pag['prazo'], __('Valor') => $moeda($pag['valor'])])" />
                        @empty
                            <flux:text size="sm">{{ __('Sem informação de pagamento no XML.') }}</flux:text>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($nfe)
                {{-- XML bruto: garante que nenhum campo fica de fora. --}}
                <div x-show="aba === 'xml'" x-cloak role="tabpanel">
                    <pre class="max-h-[32rem] overflow-auto p-4 font-mono text-xs leading-relaxed text-zinc-800 dark:text-zinc-200"><code>{{ $nfe['xml'] }}</code></pre>
                </div>
            @endif
        </div>

        {{-- Coluna lateral: manifestação e eventos. --}}
        <div class="space-y-6">
            <div class="space-y-3 border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading level="2">{{ __('Manifestação do destinatário') }}</flux:heading>
                <flux:text size="sm">{{ __('Situação atual: :s', ['s' => $document->manifestacao_status?->label() ?? __('nenhuma')]) }}</flux:text>

                @forelse ($this->manifestacoes as $tipo)
                    <flux:button size="sm" wire:click="confirmar('{{ $tipo->value }}')" class="w-full" :variant="$tipo === App\Enums\TipoEvento::CienciaOperacao ? 'primary' : 'filled'">
                        {{ $tipo->label() }}
                    </flux:button>
                @empty
                    <flux:text size="sm">{{ auth()->user()->can('manifest', $document) ? __('Nenhuma manifestação disponível para este documento.') : __('Somente donos e administradores da organização podem manifestar.') }}</flux:text>
                @endforelse
            </div>

            <div class="space-y-3 border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading level="2">{{ __('Eventos') }}</flux:heading>
                <ol class="space-y-3">
                    @forelse ($this->eventos as $evento)
                        <li wire:key="evento-{{ $evento->id }}" class="text-sm">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ App\Enums\TipoEvento::tryFrom($evento->tp_evento)?->label() ?? $evento->descricao ?? $evento->tp_evento }}</div>
                            <div class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                                {{ Fmt::dateTime($evento->data_evento) }} · {{ $evento->direcao === 'enviado' ? __('enviado por nós') : __('recebido') }}
                                @if ($evento->protocolo) · {{ __('prot. :p', ['p' => $evento->protocolo]) }} @endif
                            </div>
                        </li>
                    @empty
                        <li><flux:text size="sm">{{ __('Nenhum evento.') }}</flux:text></li>
                    @endforelse
                </ol>
            </div>
        </div>
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
