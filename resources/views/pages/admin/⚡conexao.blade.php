<?php

use App\Models\ApiToken;
use App\Models\ApiTokenAcesso;
use App\Support\Fmt;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::admin'), Title('Conexão')] class extends Component {
    use WithPagination;

    public ApiToken $token;

    /** '' (todas), 'erros' ou 'xml'. */
    #[Url] public string $filtro = '';

    public function updatedFiltro(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function acessos()
    {
        return $this->token->acessos()
            ->when($this->filtro === 'erros', fn ($q) => $q->where('status', '>=', 400))
            ->when($this->filtro === 'xml', fn ($q) => $q->where('rota', 'documents.xml'))
            ->latest('id')
            ->paginate(50);
    }

    /** @return array{notas: int, xmls: int, erros: int} */
    #[Computed]
    public function resumo(): array
    {
        $base = $this->token->acessos()->where('created_at', '>=', now()->subDays(30))->toBase();

        return [
            'notas' => (int) (clone $base)->where('status', '<', 400)->whereIn('rota', ['documents.index', 'documents.show'])->sum('quantidade'),
            'xmls' => (clone $base)->where('status', '<', 400)->where('rota', 'documents.xml')->count(),
            'erros' => (clone $base)->where('status', '>=', 400)->count(),
        ];
    }
}; ?>

@php
    $rotas = [
        'conexao' => __('Confirmou a conexão'),
        'documents.index' => __('Listou notas'),
        'documents.show' => __('Abriu uma nota'),
        'documents.xml' => __('Baixou o XML'),
        'cobertura' => __('Consultou a cobertura'),
    ];
@endphp

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="space-y-1">
        <a href="{{ route('admin.conexoes.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">
            <flux:icon name="arrow-left" variant="micro" /> {{ __('Conexões') }}
        </a>
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ $token->name }}</flux:heading>
            @if ($token->revoked_at)
                <flux:badge size="sm" color="zinc">{{ __('Revogada em :d', ['d' => Fmt::dateTime($token->revoked_at)]) }}</flux:badge>
            @else
                <flux:badge size="sm" color="green">{{ __('Ativa') }}</flux:badge>
            @endif
        </div>
        <flux:text>
            {{ $token->company ? $token->company->razao_social.' · '.Fmt::cnpj($token->company->cnpj) : __('Organização inteira: :o', ['o' => $token->organization->name]) }}
        </flux:text>
    </div>

    <x-campos :itens="[
        __('Chave') => $token->prefixo.'…',
        __('Criada') => Fmt::dateTime($token->created_at).($token->creator ? ' · '.$token->creator->name : ''),
        __('Último uso') => $token->last_used_at ? Fmt::dateTime($token->last_used_at).' · '.$token->ultimo_ip : __('nunca'),
        __('Chamadas no total') => number_format($token->total_chamadas, 0, ',', '.'),
    ]" />

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ([
            [__('Notas entregues (30 dias)'), $this->resumo['notas']],
            [__('XMLs baixados (30 dias)'), $this->resumo['xmls']],
            [__('Chamadas com erro (30 dias)'), $this->resumo['erros']],
        ] as [$rotulo, $valor])
            <div class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <flux:text size="sm">{{ $rotulo }}</flux:text>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ number_format($valor, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg" level="2">{{ __('Chamadas') }}</flux:heading>
        <flux:radio.group wire:model.live="filtro" variant="segmented" :aria-label="__('Filtrar chamadas')">
            <flux:radio value="" :label="__('Todas')" />
            <flux:radio value="xml" :label="__('XMLs')" />
            <flux:radio value="erros" :label="__('Erros')" />
        </flux:radio.group>
    </div>

    <div class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40" wire:loading.delay.class="lista-carregando" wire:target="filtro,gotoPage,nextPage,previousPage">
        <div class="grid grid-cols-[9rem_minmax(0,1fr)_5rem_4rem_8rem] gap-4 border-b border-zinc-200 px-4 py-2 text-xs font-medium tracking-wide text-zinc-500 uppercase max-md:hidden dark:border-zinc-700 dark:text-zinc-400" aria-hidden="true">
            <span>{{ __('Quando') }}</span>
            <span>{{ __('O que saiu') }}</span>
            <span class="text-end">{{ __('Notas') }}</span>
            <span>{{ __('Status') }}</span>
            <span>{{ __('IP') }}</span>
        </div>

        <x-esqueleto.linhas />

        @forelse ($this->acessos as $a)
            <div wire:key="acesso-{{ $a->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-0.5 border-b border-zinc-100 px-4 py-2.5 text-sm last:border-b-0 md:grid-cols-[9rem_minmax(0,1fr)_5rem_4rem_8rem] dark:border-zinc-800">
                <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ Fmt::dateTime($a->created_at) }}</span>
                <span class="min-w-0 truncate text-zinc-900 max-md:order-first dark:text-white">
                    {{ $rotas[$a->rota] ?? $a->rota }}
                    @if ($a->chave)
                        <span class="ms-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $a->chave }}</span>
                    @endif
                </span>
                <span class="text-end tabular-nums max-md:hidden">{{ $a->quantidade ?? '—' }}</span>
                <span>
                    <flux:badge size="sm" :color="$a->status >= 400 ? 'red' : 'green'">{{ $a->status }}</flux:badge>
                </span>
                <span class="truncate font-mono text-xs text-zinc-500 max-md:hidden dark:text-zinc-400">{{ $a->ip }}</span>
            </div>
        @empty
            <div class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nenhuma chamada registrada ainda.') }}</div>
        @endforelse
    </div>

    <div>{{ $this->acessos->links() }}</div>

    <flux:text size="sm">{{ __('O registro de chamadas fica guardado por :d dias.', ['d' => ApiTokenAcesso::DIAS_GUARDADOS]) }}</flux:text>
</section>
