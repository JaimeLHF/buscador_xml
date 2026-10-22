<?php

use App\Enums\AuditAcao;
use App\Models\AuditLog;
use App\Support\Fmt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Auditoria')] class extends Component {
    use WithPagination;

    #[Url] public string $acao = '';
    #[Url] public string $user_id = '';
    #[Url] public string $de = '';
    #[Url] public string $ate = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->managesCurrentOrganization(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return Auth::user()->currentOrganization->users()->orderBy('name')->get(['users.id', 'name']);
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::query()
            ->where('organization_id', Auth::user()->current_organization_id)
            ->with(['user:id,name', 'document:id,chave,emitente_nome,tipo'])
            ->when(AuditAcao::tryFrom($this->acao), fn ($q, $a) => $q->where('acao', $a))
            ->when($this->user_id === 'sistema', fn ($q) => $q->whereNull('user_id'))
            ->when(ctype_digit($this->user_id), fn ($q) => $q->where('user_id', (int) $this->user_id))
            ->when($this->de, fn ($q, $d) => $q->where('created_at', '>=', Carbon\CarbonImmutable::parse($d, Fmt::TIMEZONE)->startOfDay()->utc()))
            ->when($this->ate, fn ($q, $d) => $q->where('created_at', '<=', Carbon\CarbonImmutable::parse($d, Fmt::TIMEZONE)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(50);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Auditoria') }}</flux:heading>
        <flux:text>{{ __('Quem baixou, manifestou ou exportou notas desta organização, e quando. Os registros não podem ser alterados.') }}</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="acao" :label="__('Ação')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach (AuditAcao::cases() as $a)
                <flux:select.option :value="$a->value">{{ $a->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="user_id" :label="__('Quem')" class="max-w-xs">
            <flux:select.option value="">{{ __('Todos') }}</flux:select.option>
            @foreach ($this->users as $u)
                <flux:select.option :value="$u->id">{{ $u->name }}</flux:select.option>
            @endforeach
            <flux:select.option value="sistema">{{ __('Sistema / integração') }}</flux:select.option>
        </flux:select>
        <flux:input type="date" wire:model.live="de" :label="__('De')" class="max-w-40" />
        <flux:input type="date" wire:model.live="ate" :label="__('Até')" class="max-w-40" />
    </div>

    <flux:table :paginate="$this->logs">
        <flux:table.columns>
            <flux:table.column>{{ __('Quando') }}</flux:table.column>
            <flux:table.column>{{ __('Quem') }}</flux:table.column>
            <flux:table.column>{{ __('O quê') }}</flux:table.column>
            <flux:table.column>{{ __('Documento') }}</flux:table.column>
            <flux:table.column>{{ __('IP') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->logs as $log)
                <flux:table.row :key="$log->id">
                    <flux:table.cell class="whitespace-nowrap">{{ Fmt::dateTime($log->created_at) }}</flux:table.cell>
                    <flux:table.cell>{{ $log->user?->name ?? ($log->dados['token'] ?? null ? __('API: :n', ['n' => $log->dados['token']]) : __('Sistema')) }}</flux:table.cell>
                    <flux:table.cell class="min-w-48 whitespace-normal">
                        {{ $log->acao->label() }}@if ($log->descricao): {{ $log->descricao }}@endif
                        @if ($log->dados['protocolo'] ?? null)
                            <flux:text size="sm">{{ __('prot. :p', ['p' => $log->dados['protocolo']]) }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="min-w-48 whitespace-normal">
                        @if ($log->document)
                            <flux:link :href="route('documents.show', $log->dfe_document_id)" wire:navigate>{{ $log->document->emitente_nome ?? $log->document->chave }}</flux:link>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $log->ip }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">{{ __('Nenhum registro.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
