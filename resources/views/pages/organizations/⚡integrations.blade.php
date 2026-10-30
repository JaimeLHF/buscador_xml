<?php

use App\Enums\AuditAcao;
use App\Jobs\SendWebhook;
use App\Services\Webhooks\WebhookUrl;
use App\Support\Audit;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Integrações')] class extends Component {
    public string $webhookUrl = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->managesCurrentOrganization(), 403);

        $this->webhookUrl = (string) $this->organization->webhook_url;
    }

    #[Computed]
    public function organization()
    {
        return Auth::user()->currentOrganization;
    }

    #[Computed]
    public function tokens()
    {
        return $this->organization->apiTokens()->active()->with('company:id,razao_social,cnpj')->latest()->get();
    }

    public function salvarWebhook(): void
    {
        abort_unless(Auth::user()->managesCurrentOrganization(), 403);
        $url = trim($this->webhookUrl);

        if ($url !== '') {
            $this->validate(['webhookUrl' => ['url:https,http', 'max:500']]);

            try {
                WebhookUrl::resolve($url);
            } catch (InvalidArgumentException $e) {
                $this->addError('webhookUrl', $e->getMessage());

                return;
            }
        }

        $this->organization->update([
            'webhook_url' => $url ?: null,
            // O segredo nasce junto com o primeiro endereço e é mantido nas trocas seguintes.
            'webhook_secret' => $url ? ($this->organization->webhook_secret ?? Str::random(48)) : null,
            'webhook_ultimo_status' => null,
            'webhook_ultimo_envio_at' => null,
        ]);
        Audit::record(AuditAcao::WebhookAlterado, $this->organization->id, descricao: $url ?: __('desligado'));

        Flux::toast(variant: 'success', text: $url ? __('Webhook salvo.') : __('Webhook desligado.'));
    }

    public function novoSegredo(): void
    {
        abort_unless(Auth::user()->managesCurrentOrganization() && $this->organization->webhook_url, 403);
        $this->organization->update(['webhook_secret' => Str::random(48)]);
        Audit::record(AuditAcao::WebhookAlterado, $this->organization->id, descricao: __('novo segredo'));
    }

    public function testarWebhook(): void
    {
        abort_unless(Auth::user()->managesCurrentOrganization() && $this->organization->webhook_url, 403);
        SendWebhook::dispatch($this->organization, null, 'teste');

        Flux::toast(text: __('Aviso de teste enviado para a fila. Atualize a página em alguns segundos para ver o resultado.'));
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Integrações') }}</flux:heading>
        <flux:text>{{ __('Ligue o ERP ou o sistema do contador ao Buscador: ele consulta as notas pela API e recebe um aviso quando chega nota nova.') }}</flux:text>
    </div>

    {{-- Chaves de API --}}
    <div class="space-y-4 border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg" level="2">{{ __('Chaves de acesso à API') }}</flux:heading>
        <flux:text size="sm">{{ __('As chaves são criadas pelo suporte do FinderXML, uma por empresa e por sistema, e dão acesso só de leitura às notas daquela empresa. Para pedir ou revogar uma chave, fale com o suporte.') }}</flux:text>

        @if ($this->tokens->isNotEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Sistema') }}</flux:table.column>
                    <flux:table.column>{{ __('Empresa') }}</flux:table.column>
                    <flux:table.column>{{ __('Começa com') }}</flux:table.column>
                    <flux:table.column>{{ __('Último uso') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tokens as $token)
                        <flux:table.row :key="$token->id">
                            <flux:table.cell>{{ $token->name }}</flux:table.cell>
                            <flux:table.cell>{{ $token->company?->razao_social ?? __('Todas as empresas') }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $token->prefixo }}…</flux:table.cell>
                            <flux:table.cell>{{ $token->last_used_at ? Fmt::dateTime($token->last_used_at) : __('nunca') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            </div>
        @endif

        <details class="text-sm">
            <summary class="cursor-pointer font-medium">{{ __('Como usar a API') }}</summary>
            <div class="mt-3 space-y-2 text-zinc-700 dark:text-zinc-300">
                <p>{{ __('Envie a chave no cabeçalho Authorization: Bearer <chave>. Limite de 120 chamadas por minuto por chave.') }}</p>
                <ul class="list-disc space-y-1 ps-5 font-mono text-xs">
                    <li>GET {{ url('/api/v1/conexao') }}</li>
                    <li>GET {{ url('/api/v1/documents') }}?alterados_desde=2026-10-01T00:00:00-03:00</li>
                    <li>GET {{ url('/api/v1/documents') }}/{chave}</li>
                    <li>GET {{ url('/api/v1/documents') }}/{chave}/xml</li>
                </ul>
                <p>{{ __('A lista vem em ordem de alteração. Guarde o "atualizado_em" da última nota e use em "alterados_desde" na próxima vez; siga "proximo_cursor" (parâmetro cursor) até ele vir vazio. Filtros: tipo (nfe, cte), situacao, xml (completo, resumo), de, ate, busca, por_pagina (até 100).') }}</p>
            </div>
        </details>
    </div>

    {{-- Webhook --}}
    <div class="space-y-4 border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg" level="2">{{ __('Aviso automático (webhook)') }}</flux:heading>
        <flux:text size="sm">{{ __('Quando uma nota chega, ganha o XML completo ou é cancelada, o Buscador envia um POST com os dados dela para este endereço.') }}</flux:text>

        <form wire:submit="salvarWebhook" class="flex flex-wrap items-end gap-4">
            <flux:input wire:model="webhookUrl" type="url" :label="__('Endereço (https://)')" placeholder="https://erp.exemplo.com.br/buscador" class="min-w-64 flex-1" />
            <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
        </form>

        @if ($this->organization->webhook_url)
            <x-campos :itens="[
                __('Segredo da assinatura') => $this->organization->webhook_secret,
                __('Último envio') => $this->organization->webhook_ultimo_envio_at ? Fmt::dateTime($this->organization->webhook_ultimo_envio_at).' · '.$this->organization->webhook_ultimo_status : __('nenhum ainda'),
            ]" />
            <flux:text size="sm">{{ __('Confira cada aviso: o cabeçalho X-Buscador-Assinatura traz sha256= seguido do HMAC-SHA256 do corpo com este segredo. Avisos com erro são reenviados até 5 vezes.') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" wire:click="testarWebhook">{{ __('Enviar teste') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="novoSegredo" wire:confirm="{{ __('Gerar um novo segredo? O ERP precisa ser atualizado com ele.') }}">{{ __('Gerar novo segredo') }}</flux:button>
            </div>
        @endif
    </div>
</section>
