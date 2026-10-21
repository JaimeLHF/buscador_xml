<?php

use App\Enums\ManifestacaoStatus;
use App\Models\Certificate;
use App\Models\DfeDocument;
use App\Models\SyncState;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function organization()
    {
        return Auth::user()->currentOrganization;
    }

    #[Computed]
    public function stats(): array
    {
        $companyIds = $this->organization->companies()->select('id');
        $documents = DfeDocument::whereIn('company_id', $companyIds);

        return [
            'empresas' => $this->organization->companies()->count(),
            'documentos_30d' => (clone $documents)->where('data_emissao', '>=', now()->subDays(30))->count(),
            'sem_xml' => (clone $documents)->where('tem_xml_completo', false)->count(),
            'manifestacao_erro' => (clone $documents)->where('manifestacao_status', ManifestacaoStatus::Erro)->count(),
            'certificados_vencendo' => Certificate::whereIn('company_id', $companyIds)->where('active', true)->where('valid_to', '<=', now()->addDays(30))->count(),
            'com_erro' => SyncState::whereIn('company_id', $companyIds)->where(fn ($q) => $q->whereNotNull('last_error')->orWhere('consecutive_656', '>', 0))->count(),
        ];
    }

    /** Alertas da organização atual (o usuário pode estar em várias). */
    #[Computed]
    public function alerts()
    {
        return Auth::user()->unreadNotifications()
            ->where('data->organization_id', $this->organization->id)
            ->latest()->limit(20)->get();
    }

    public function dismiss(string $id): void
    {
        Auth::user()->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);
        unset($this->alerts);
    }
}; ?>

<section class="w-full space-y-8">
    <flux:heading size="xl">{{ $this->organization->name }}</flux:heading>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            ['Empresas', $this->stats['empresas'], route('companies.index')],
            ['Documentos (30 dias)', $this->stats['documentos_30d'], route('documents.index')],
            ['Somente resumo (sem XML completo)', $this->stats['sem_xml'], route('documents.index', ['xml' => 'resumo'])],
            ['Manifestações com erro', $this->stats['manifestacao_erro'], route('documents.index', ['manifestacao' => 'erro'])],
            ['Certificados vencendo em 30 dias', $this->stats['certificados_vencendo'], route('companies.index')],
            ['Empresas com erro de sincronização', $this->stats['com_erro'], route('sefaz-log')],
        ] as [$label, $value, $url])
            <a href="{{ $url }}" wire:navigate>
                <flux:card class="h-full hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                    <flux:text>{{ __($label) }}</flux:text>
                    <flux:heading size="xl" class="mt-2 tabular-nums">{{ number_format($value, 0, ',', '.') }}</flux:heading>
                </flux:card>
            </a>
        @endforeach
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Alertas') }}</flux:heading>

        @forelse ($this->alerts as $alert)
            <flux:callout :variant="$alert->data['level'] ?? 'warning'" icon="bell-alert" wire:key="alert-{{ $alert->id }}">
                <flux:callout.heading>{{ $alert->data['message'] }}</flux:callout.heading>
                <x-slot name="actions">
                    <flux:button size="sm" :href="$alert->data['url']" wire:navigate>{{ __('Ver') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="dismiss('{{ $alert->id }}')">{{ __('Dispensar') }}</flux:button>
                </x-slot>
            </flux:callout>
        @empty
            <flux:text>{{ __('Nenhum alerta pendente.') }}</flux:text>
        @endforelse
    </div>
</section>
