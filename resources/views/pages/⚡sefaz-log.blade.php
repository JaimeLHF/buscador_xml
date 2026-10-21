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

new #[Title('Log SEFAZ')] class extends Component {
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
    <flux:heading size="xl">{{ __('Log de requisições à SEFAZ') }}</flux:heading>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="company_id" :label="__('Empresa')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach ($this->companies as $c)
                <flux:select.option :value="$c->id">{{ $c->razao_social }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="servico" :label="__('Serviço')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todos') }}</flux:select.option>
            <flux:select.option value="dist_nsu">{{ __('Distribuição (NSU)') }}</flux:select.option>
            <flux:select.option value="cons_chave">{{ __('Consulta por chave') }}</flux:select.option>
            <flux:select.option value="cons_nsu">{{ __('Consulta por NSU') }}</flux:select.option>
            <flux:select.option value="manifestacao">{{ __('Manifestação') }}</flux:select.option>
        </flux:select>
        <flux:checkbox wire:model.live="somenteErros" :label="__('Somente erros e rejeições')" />
    </div>

    <flux:table :paginate="$this->requests">
        <flux:table.columns>
            <flux:table.column>{{ __('Data') }}</flux:table.column>
            <flux:table.column>{{ __('Empresa') }}</flux:table.column>
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
                    <flux:table.cell>{{ $r->company->razao_social }}</flux:table.cell>
                    <flux:table.cell>{{ $r->servico->value }}{{ $r->tp_amb === 1 ? '' : ' · hom' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($r->erro)
                            <span class="text-red-600 dark:text-red-400">{{ $r->erro }}</span>
                        @else
                            <span class="font-mono">{{ $r->cstat }}</span> {{ $r->xmotivo }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">
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
</section>
