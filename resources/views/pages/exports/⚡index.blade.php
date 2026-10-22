<?php

use App\Support\Fmt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Downloads')] class extends Component {
    #[Computed]
    public function exports()
    {
        return Auth::user()->currentOrganization->exports()->where('user_id', Auth::id())->latest()->limit(30)->get();
    }

    #[Computed]
    public function pending(): bool
    {
        return $this->exports->contains(fn ($e) => ! $e->status->isFinished());
    }
}; ?>

<section class="w-full space-y-6" @if ($this->pending) wire:poll.3s @endif>
    <div>
        <flux:heading size="xl" level="1">{{ __('Downloads') }}</flux:heading>
        <flux:subheading>{{ __('Arquivos ZIP gerados pelo botão "Baixar XMLs" da tela de notas. Ficam disponíveis por 7 dias.') }}</flux:subheading>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Pedido em') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('XMLs') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->exports as $export)
                <flux:table.row :key="$export->id">
                    <flux:table.cell>{{ Fmt::dateTime($export->created_at) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($export->status) { App\Enums\ExportStatus::Done => 'green', App\Enums\ExportStatus::Failed => 'red', default => 'amber' }">{{ $export->status->label() }}</flux:badge>
                        @if ($export->error) <flux:text size="sm">{{ $export->error }}</flux:text> @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $export->status->isFinished() ? $export->total : '…' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($export->path)
                            <flux:button size="sm" icon="arrow-down-tray" :href="route('exports.download', $export)">{{ __('Baixar') }}</flux:button>
                        @elseif ($export->status === App\Enums\ExportStatus::Done)
                            <flux:text size="sm">{{ __('Nenhum XML encontrado') }}</flux:text>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center">{{ __('Nenhum download ainda. Na tela de notas, filtre o que precisa e clique em "Baixar XMLs".') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
