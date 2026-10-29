<?php

use App\Enums\AuditAcao;
use App\Enums\ChaveHistoricaStatus;
use App\Models\ChaveHistorica;
use App\Models\Company;
use App\Services\MeuDanfe\MeuDanfeClient;
use App\Services\MeuDanfe\SpedChaves;
use App\Support\Audit;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Chaves do SPED')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    /** Teto de uma consulta em lote no Meu Danfe (cada chave é cobrada). */
    public const LOTE_MAXIMO = 1000;

    public const PRECO_POR_CHAVE = 0.03;

    public Company $company;

    /** @var list<Illuminate\Http\UploadedFile> */
    public array $speds = [];

    /** @var array{lidas: int, novas: int, com_xml: int}|null */
    public ?array $importacao = null;

    #[Url] public string $status = '';
    #[Url] public string $mes = '';
    #[Url] public string $q = '';

    /** @var list<int|string> */
    public array $selecionados = [];

    public function mount(Company $company): void
    {
        Gate::authorize('update', $company);

        $this->company = $company;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'mes', 'q'], true)) {
            $this->resetPage();
        }
    }

    /** Registra as chaves dos arquivos e mostra as que ainda não têm XML: nada é consultado sem alguém marcar. */
    public function importar(): void
    {
        Gate::authorize('update', $this->company);

        $this->validate(['speds' => 'required|array|max:24', 'speds.*' => 'file|mimes:txt|max:51200']);

        $total = ['lidas' => 0, 'novas' => 0, 'com_xml' => 0];

        foreach ($this->speds as $arquivo) {
            $r = SpedChaves::importar($this->company, (string) file_get_contents($arquivo->getRealPath()));
            $total = ['lidas' => $total['lidas'] + $r['lidas'], 'novas' => $total['novas'] + $r['novas'], 'com_xml' => $total['com_xml'] + $r['com_xml']];
        }

        $this->reset('speds', 'mes', 'q', 'selecionados');
        $this->importacao = $total;
        $this->status = ChaveHistoricaStatus::Importada->value;
        $this->resetPage();
    }

    /** @return Builder<ChaveHistorica> */
    private function filtradas(): Builder
    {
        $termo = preg_replace('/\D/', '', $this->q);

        return $this->company->chavesHistoricas()->getQuery()
            ->when(ChaveHistoricaStatus::tryFrom($this->status), fn (Builder $q, ChaveHistoricaStatus $s) => $q->where('status', $s))
            ->when(preg_match('/^\d{4}-\d{2}$/', $this->mes) === 1, fn (Builder $q) => $q->whereBetween('sped_data', [$this->mes.'-01', Carbon\CarbonImmutable::parse($this->mes.'-01')->endOfMonth()->toDateString()]))
            ->when($termo !== '', fn (Builder $q) => $q->where('chave', 'like', '%'.$termo.'%'));
    }

    #[Computed]
    public function chaves()
    {
        return $this->filtradas()->orderByDesc('sped_data')->orderBy('id')->paginate(50);
    }

    /** Nome do fornecedor a partir das notas que a empresa já recebeu dele (o SPED só traz a chave). */
    #[Computed]
    public function fornecedores(): array
    {
        $cnpjs = $this->chaves->getCollection()->map(fn (ChaveHistorica $c) => substr($c->chave, 6, 14))->unique()->values()->all();

        return $this->company->documents()->toBase()->whereIn('emitente_cnpj', $cnpjs)->whereNotNull('emitente_nome')
            ->groupBy('emitente_cnpj')->selectRaw('emitente_cnpj, max(emitente_nome) as nome')->pluck('nome', 'emitente_cnpj')->all();
    }

    /** @return array<string, int> */
    #[Computed]
    public function contagem(): array
    {
        return $this->company->chavesHistoricas()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** @return list<string> AAAA-MM dos meses que têm chaves */
    #[Computed]
    public function meses(): array
    {
        return $this->company->chavesHistoricas()->toBase()->whereNotNull('sped_data')
            ->selectRaw("distinct to_char(sped_data, 'YYYY-MM') as mes")->orderByDesc('mes')->pluck('mes')->all();
    }

    #[Computed]
    public function selecionaveisFiltradas(): int
    {
        return $this->filtradas()->whereIn('status', self::statusSelecionaveis())->count();
    }

    public function marcarFiltradas(): void
    {
        $this->selecionados = $this->filtradas()->whereIn('status', self::statusSelecionaveis())
            ->orderByDesc('sped_data')->orderBy('id')->limit(self::LOTE_MAXIMO)->pluck('id')->all();
    }

    public function desmarcar(): void
    {
        $this->selecionados = [];
    }

    public function consultar(): void
    {
        Gate::authorize('update', $this->company);

        if (! MeuDanfeClient::configurado()) {
            Flux::toast(variant: 'danger', text: __('A integração com o Meu Danfe não está configurada.'));

            return;
        }

        // Revalida no servidor: a seleção vem do navegador e só conta o que é desta empresa e ainda pode ser consultado.
        $ids = array_slice(array_values(array_unique(array_map('intval', $this->selecionados))), 0, self::LOTE_MAXIMO);
        $n = SpedChaves::enfileirar($this->company, $ids);

        if ($n > 0) {
            Audit::record(AuditAcao::ConsultaMeuDanfe, $this->company->organization_id, descricao: $this->company->razao_social, dados: ['chaves' => $n]);
        }

        $this->selecionados = [];
        unset($this->chaves, $this->contagem, $this->selecionaveisFiltradas);
        Flux::modal('consultar-meudanfe')->close();

        $n === 0
            ? Flux::toast(variant: 'warning', text: __('Nenhuma das chaves marcadas pode ser consultada.'))
            : Flux::toast(variant: 'success', text: trans_choice(':n chave foi para a consulta no Meu Danfe.|:n chaves foram para a consulta no Meu Danfe.', $n, ['n' => $n]));
    }

    /** @return list<string> */
    private static function statusSelecionaveis(): array
    {
        return array_values(array_map(fn (ChaveHistoricaStatus $s) => $s->value, array_filter(ChaveHistoricaStatus::cases(), fn (ChaveHistoricaStatus $s) => $s->selecionavel())));
    }
}; ?>

@php
    $naFila = $this->contagem[ChaveHistoricaStatus::Pendente->value] ?? 0;
    $total = array_sum($this->contagem);
    $custo = count($selecionados) * $this::PRECO_POR_CHAVE;
@endphp
<section class="w-full space-y-6" @if ($naFila > 0) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('companies.show', $company)" wire:navigate class="text-sm">← {{ $company->nome_fantasia ?: $company->razao_social }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-1">{{ __('Chaves do SPED') }}</flux:heading>
            <flux:text class="mt-1 max-w-2xl">{{ __('Envie os TXT do SPED EFD ICMS/IPI baixados do ReceitanetBX. As notas de entrada aparecem aqui; marque as que quer buscar no Meu Danfe (R$ 0,03 por chave) e consulte em lote.') }}</flux:text>
        </div>
    </div>

    <flux:card class="space-y-4">
        <form wire:submit="importar" class="flex flex-wrap items-end gap-3">
            <flux:input type="file" wire:model="speds" multiple accept=".txt" :label="__('Arquivos SPED (.txt)')" />
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="speds,importar">{{ __('Enviar e ler chaves') }}</flux:button>
        </form>
        <flux:error name="speds" />
        <flux:error name="speds.*" />

        @if ($importacao)
            <flux:callout icon="document-check" color="green">
                <flux:callout.heading>{{ __(':l notas de entrada no SPED', ['l' => number_format($importacao['lidas'], 0, ',', '.')]) }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __(':x já tinham o XML · :n novas aguardando seleção. Elas estão listadas abaixo.', ['x' => number_format($importacao['com_xml'], 0, ',', '.'), 'n' => number_format($importacao['novas'], 0, ',', '.')]) }}
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:card>

    @if ($total > 0)
        {{-- Resumo: clicar num status filtra a lista. --}}
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Filtrar por situação') }}">
            <flux:button size="sm" :variant="$status === '' ? 'primary' : 'outline'" wire:click="$set('status', '')">{{ __('Todas') }} · {{ number_format($total, 0, ',', '.') }}</flux:button>
            @foreach (ChaveHistoricaStatus::cases() as $st)
                @if ($n = $this->contagem[$st->value] ?? 0)
                    <flux:button size="sm" :variant="$status === $st->value ? 'primary' : 'outline'" wire:click="$set('status', '{{ $st->value }}')">
                        <span class="inline-block size-2 rounded-full {{ match ($st->cor()) { 'amber' => 'bg-amber-500', 'blue' => 'bg-blue-500', 'green' => 'bg-green-500', 'red' => 'bg-red-500', default => 'bg-zinc-400' } }}" aria-hidden="true"></span>
                        {{ $st->label() }} · {{ number_format($n, 0, ',', '.') }}
                    </flux:button>
                @endif
            @endforeach
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.400ms="q" icon="magnifying-glass" :placeholder="__('Chave ou CNPJ do fornecedor')" class="max-w-xs" clearable :aria-label="__('Buscar chave')" />
            <flux:select wire:model.live="mes" class="w-44" :aria-label="__('Mês do SPED')">
                <flux:select.option value="">{{ __('Todos os meses') }}</flux:select.option>
                @foreach ($this->meses as $m)
                    <flux:select.option :value="$m">{{ Carbon\CarbonImmutable::parse($m.'-01')->locale('pt_BR')->translatedFormat('M/Y') }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->selecionaveisFiltradas > 0 || $selecionados)
            <div class="flex flex-wrap items-center justify-between gap-3 border border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <flux:text>
                    {{ trans_choice(':count chave marcada|:count chaves marcadas', count($selecionados), ['count' => count($selecionados)]) }}
                    @if ($selecionados)
                        · {{ __('custo estimado :v', ['v' => Fmt::money($custo)]) }}
                    @endif
                </flux:text>
                <div class="flex flex-wrap gap-2">
                    @if ($selecionados)
                        <flux:button size="sm" variant="ghost" wire:click="desmarcar">{{ __('Desmarcar') }}</flux:button>
                        <flux:modal.trigger name="consultar-meudanfe">
                            <flux:button size="sm" variant="primary" icon="magnifying-glass-circle">{{ __('Consultar no Meu Danfe') }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button size="sm" variant="ghost" wire:click="marcarFiltradas">{{ __('Marcar todas as filtradas (:n)', ['n' => number_format(min($this->selecionaveisFiltradas, $this::LOTE_MAXIMO), 0, ',', '.')]) }}</flux:button>
                    @endif
                </div>
            </div>
        @endif

        <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.class="opacity-60" wire:target="status,mes,q,gotoPage,nextPage,previousPage,importar">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-10"><span class="sr-only">{{ __('Marcar') }}</span></flux:table.column>
                    <flux:table.column>{{ __('Fornecedor') }}</flux:table.column>
                    <flux:table.column>{{ __('Nº') }}</flux:table.column>
                    <flux:table.column>{{ __('Emissão (SPED)') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Valor (SPED)') }}</flux:table.column>
                    <flux:table.column>{{ __('Situação') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->chaves as $c)
                        @php
                            $cnpj = substr($c->chave, 6, 14);
                            $numero = ltrim(substr($c->chave, 25, 9), '0');
                        @endphp
                        <flux:table.row :key="'chave-'.$c->id">
                            <flux:table.cell>
                                @if ($c->status->selecionavel())
                                    <input type="checkbox" wire:model.live="selecionados" value="{{ $c->id }}" aria-label="{{ __('Marcar a nota :n', ['n' => $numero]) }}" class="size-4 cursor-pointer accent-zinc-800 dark:accent-white">
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $this->fornecedores[$cnpj] ?? Fmt::cnpj($cnpj) }}</div>
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ __('Chave de acesso') }}">{{ trim(chunk_split($c->chave, 4, ' ')) }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $numero }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $c->sped_data?->format('d/m/Y') ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $c->sped_valor !== null ? Fmt::money($c->sped_valor) : '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$c->status->cor()">{{ $c->status->label() }}</flux:badge>
                                @if ($c->ultimo_erro && $c->status !== ChaveHistoricaStatus::Encontrada)
                                    <div class="mt-1 max-w-56 truncate text-xs text-zinc-500 dark:text-zinc-400" title="{{ $c->ultimo_erro }}">{{ $c->ultimo_erro }}</div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">{{ __('Nenhuma chave com esses filtros.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:pagination :paginator="$this->chaves" />
    @endif

    <flux:modal name="consultar-meudanfe" class="md:w-[32rem]">
        <form wire:submit="consultar" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">{{ trans_choice('Consultar :count chave no Meu Danfe|Consultar :count chaves no Meu Danfe', count($selecionados), ['count' => count($selecionados)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('O Meu Danfe cobra cada consulta (R$ 0,03 por chave). Custo estimado: :v.', ['v' => Fmt::money($custo)]) }}</flux:text>
                <flux:text class="mt-2">{{ __('A busca começa agora, uma chave por vez; a situação na lista atualiza sozinha.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Consultar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
