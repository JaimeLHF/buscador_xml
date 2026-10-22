<?php

use App\Enums\SefazServico;
use App\Models\SefazRequest;
use App\Support\Fmt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Histórico de buscas')] class extends Component {
    use WithPagination;

    #[Url] public string $company_id = '';
    #[Url] public string $servico = '';
    #[Url] public bool $somenteErros = false;

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function companies()
    {
        return Auth::user()->currentOrganization->companies()->orderBy('razao_social')->get(['id', 'razao_social']);
    }

    #[Computed]
    public function requests()
    {
        return SefazRequest::query()
            ->whereIn('company_id', Auth::user()->currentOrganization->companies()->select('id'))
            ->with('company:id,razao_social')
            ->when($this->company_id, fn ($q) => $q->where('company_id', (int) $this->company_id))
            ->when(SefazServico::tryFrom($this->servico), fn ($q, $s) => $q->where('servico', $s))
            ->when($this->somenteErros, fn ($q) => $q->where(fn ($q) => $q->whereNotNull('erro')->orWhereNotIn('cstat', ['137', '138', '135', '136'])))
            ->latest('id')
            ->paginate(50);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="space-y-1">
        <flux:link :href="route('companies.index')" wire:navigate class="text-sm">← {{ __('Empresas') }}</flux:link>
        <flux:heading size="xl" level="1">{{ __('Histórico de buscas na SEFAZ') }}</flux:heading>
        <flux:text>{{ __('Cada conversa do sistema com a SEFAZ: buscas de notas, downloads de XML e manifestações.') }}</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        @if ($this->companies->count() > 1)
        <flux:select wire:model.live="company_id" :label="__('Empresa')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach ($this->companies as $c)
                <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
            @endforeach
        </flux:select>
        @endif
        <flux:select wire:model.live="servico" :label="__('Serviço')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todos') }}</flux:select.option>
            @foreach (App\Enums\SefazServico::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:checkbox wire:model.live="somenteErros" :label="__('Somente erros e rejeições')" />
    </div>

    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:table :paginate="$this->requests">
        <flux:table.columns>
            <flux:table.column>{{ __('Data') }}</flux:table.column>
            @if ($this->companies->count() > 1)
                <flux:table.column>{{ __('Empresa') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('Serviço') }}</flux:table.column>
            <flux:table.column>{{ __('Retorno') }}</flux:table.column>
            <flux:table.column>{{ __('NSU enviado → retornado / máx.') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Docs') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Tempo') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->requests as $r)
                <flux:table.row :key="$r->id">
                    <flux:table.cell class="whitespace-nowrap">{{ Fmt::dateTime($r->created_at) }}</flux:table.cell>
                    @if ($this->companies->count() > 1)
                        <flux:table.cell>{{ $r->company->razao_social }}</flux:table.cell>
                    @endif
                    <flux:table.cell>{{ $r->servico->label() }}{{ $r->tp_amb === 1 ? '' : ' · '.__('homologação') }}</flux:table.cell>
                    <flux:table.cell class="min-w-56 whitespace-normal">
                        @if ($r->erro)
                            <span class="text-red-600 dark:text-red-400">{{ $r->erro }}</span>
                        @else
                            <span class="font-mono">{{ $r->cstat }}</span> {{ $r->xmotivo }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="min-w-40 font-mono text-xs break-all whitespace-normal">
                        @if ($r->ult_nsu_enviado)
                            {{ ltrim($r->ult_nsu_enviado, '0') ?: '0' }} → {{ ltrim((string) $r->ult_nsu_retornado, '0') ?: '0' }} / {{ ltrim((string) $r->max_nsu, '0') ?: '0' }}
                        @elseif ($r->chave)
                            {{ $r->chave }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $r->documentos }}</flux:table.cell>
                    <flux:table.cell align="end" class="whitespace-nowrap">{{ $r->duracao_ms }} ms</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">{{ __('Nenhuma requisição registrada.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>
</section>
