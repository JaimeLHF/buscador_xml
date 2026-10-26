<?php

use App\Actions\Organizations\InviteMember;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Equipe')] class extends Component {
    public string $email = '';
    public string $role = 'member';

    #[Computed]
    public function organization()
    {
        return Auth::user()->currentOrganization;
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) Auth::user()->roleIn($this->organization)?->canManage();
    }

    #[Computed]
    public function members()
    {
        return $this->organization->users()->orderBy('name')->get();
    }

    #[Computed]
    public function invitations()
    {
        return $this->organization->invitations()->pending()->latest()->get();
    }

    public function invite(InviteMember $action): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'email' => ['required', 'email', 'max:255', Rule::notIn($this->members->pluck('email')->map(fn ($e) => strtolower($e))->all())],
            'role' => ['required', Rule::in([OrganizationRole::Admin->value, OrganizationRole::Member->value])],
        ], ['email.not_in' => __('Este e-mail já é membro da organização.')]);

        $action->handle($this->organization, Auth::user(), $this->email, OrganizationRole::from($this->role));

        $this->reset('email', 'role');
        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Convite enviado.'));
    }

    public function changeRole(int $userId, string $role): void
    {
        abort_unless($this->canManage, 403);
        $member = $this->member($userId);
        $newRole = OrganizationRole::from($role);
        abort_if($newRole === OrganizationRole::Owner || $member->pivot->role === OrganizationRole::Owner, 403);

        $this->organization->users()->updateExistingPivot($member->id, ['role' => $newRole]);
        unset($this->members);
    }

    public function remove(int $userId): void
    {
        abort_unless($this->canManage, 403);
        $member = $this->member($userId);
        abort_if($member->pivot->role === OrganizationRole::Owner || $member->id === Auth::id(), 403);

        $this->organization->users()->detach($member->id);
        $this->organization->invitations()->where('email', strtolower($member->email))->delete();

        if ($member->current_organization_id === $this->organization->id) {
            $member->forceFill(['current_organization_id' => null])->save();
        }

        unset($this->members);
        Flux::toast(text: __('Membro removido.'));
    }

    public function regenerateJoinLink(): void
    {
        abort_unless($this->canManage, 403);
        $this->organization->regenerateJoinLink();
        Flux::toast(variant: 'success', text: __('Novo link gerado. O anterior deixou de funcionar.'));
    }

    public function disableJoinLink(): void
    {
        abort_unless($this->canManage, 403);
        $this->organization->disableJoinLink();
        Flux::toast(text: __('Link de convite desativado.'));
    }

    public function revoke(int $invitationId): void
    {
        abort_unless($this->canManage, 403);
        $this->organization->invitations()->whereKey($invitationId)->delete();
        unset($this->invitations);
    }

    private function member(int $userId): User
    {
        return $this->organization->users()->whereKey($userId)->firstOrFail();
    }
}; ?>

<section class="w-full max-w-4xl space-y-10">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Equipe') }}</flux:heading>
        <flux:text>{{ trans_choice(':n pessoa vê as notas de :org.|:n pessoas veem as notas de :org.', $this->members->count(), ['n' => $this->members->count(), 'org' => $this->organization->name]) }}</flux:text>
    </div>

    <div class="divide-y divide-zinc-200 border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($this->members as $member)
            @php($isOwner = $member->pivot->role === OrganizationRole::Owner)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" wire:key="member-{{ $member->id }}">
                <flux:avatar circle size="sm" :name="$member->name" />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $member->name }}</span>
                        @if ($member->id === auth()->id())
                            <flux:badge size="sm" color="zinc">{{ __('Você') }}</flux:badge>
                        @endif
                    </div>
                    <div class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $member->email }}</div>
                </div>

                <div class="hidden text-xs tabular-nums text-zinc-500 sm:block dark:text-zinc-400">
                    {{ __('desde :d', ['d' => Fmt::date($member->pivot->created_at)]) }}
                </div>

                <div class="flex w-full items-center gap-1 ps-12 sm:w-48 sm:shrink-0 sm:justify-end sm:ps-0">
                    @if ($this->canManage && ! $isOwner)
                        <flux:select size="sm" wire:change="changeRole({{ $member->id }}, $event.target.value)" :aria-label="__('Papel de :name', ['name' => $member->name])">
                            @foreach ([OrganizationRole::Admin, OrganizationRole::Member] as $r)
                                <flux:select.option :value="$r->value" :selected="$member->pivot->role === $r">{{ $r->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $member->pivot->role->label() }}</span>
                    @endif

                    @if ($this->canManage && ! $isOwner && $member->id !== auth()->id())
                        <flux:tooltip :content="__('Remover da equipe')">
                            <flux:button size="sm" variant="ghost" icon="user-minus" wire:click="remove({{ $member->id }})" wire:confirm="{{ __('Remover :name da organização?', ['name' => $member->name]) }}" :aria-label="__('Remover :name', ['name' => $member->name])" />
                        </flux:tooltip>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($this->canManage)
            @foreach ($this->invitations as $inv)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" wire:key="inv-{{ $inv->id }}">
                    <flux:avatar circle size="sm" icon="envelope" />

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $inv->email }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Convite pendente · :role', ['role' => $inv->role->label()]) }}</div>
                    </div>

                    <div class="hidden text-xs tabular-nums text-zinc-500 sm:block dark:text-zinc-400">
                        {{ __('expira em :d', ['d' => Fmt::date($inv->expires_at)]) }}
                    </div>

                    <div class="flex w-full ps-12 sm:w-48 sm:shrink-0 sm:justify-end sm:ps-0">
                        <flux:button size="sm" variant="ghost" wire:click="revoke({{ $inv->id }})">{{ __('Cancelar convite') }}</flux:button>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    @if ($this->canManage)
        <div class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Adicionar pessoas') }}</flux:heading>

            <div class="grid border border-zinc-200 bg-white md:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex items-start gap-3">
                        <flux:icon.link class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                        <div>
                            <flux:heading>{{ __('Link de convite') }}</flux:heading>
                            <flux:text size="sm">{{ __('Quem abrir o link cria a conta e entra como membro. Envie só para pessoas da empresa.') }}</flux:text>
                        </div>
                    </div>

                    @if ($joinUrl = $this->organization->joinUrl())
                        <flux:input :value="$joinUrl" readonly copyable :aria-label="__('Link de convite')" />
                        <div class="mt-auto flex gap-2">
                            <flux:button size="sm" icon="arrow-path" wire:click="regenerateJoinLink" wire:confirm="{{ __('O link atual vai parar de funcionar. Continuar?') }}">{{ __('Gerar novo') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="disableJoinLink">{{ __('Desativar') }}</flux:button>
                        </div>
                    @else
                        <div class="mt-auto">
                            <flux:button variant="primary" icon="link" wire:click="regenerateJoinLink">{{ __('Gerar link de convite') }}</flux:button>
                        </div>
                    @endif
                </div>

                <form wire:submit="invite" class="flex flex-col gap-4 border-t border-zinc-200 p-5 md:border-t-0 md:border-l dark:border-zinc-700">
                    <div class="flex items-start gap-3">
                        <flux:icon.envelope class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                        <div>
                            <flux:heading>{{ __('Convidar por e-mail') }}</flux:heading>
                            <flux:text size="sm">{{ __('A pessoa recebe um link pessoal, válido por 7 dias.') }}</flux:text>
                        </div>
                    </div>

                    <flux:input wire:model="email" type="email" :placeholder="__('nome@empresa.com.br')" required :aria-label="__('E-mail')" />

                    <div class="mt-auto flex gap-2">
                        <flux:select wire:model="role" size="sm" class="max-w-40" :aria-label="__('Papel')">
                            <flux:select.option value="member">{{ OrganizationRole::Member->label() }}</flux:select.option>
                            <flux:select.option value="admin">{{ OrganizationRole::Admin->label() }}</flux:select.option>
                        </flux:select>
                        <flux:button size="sm" type="submit" variant="primary" icon="paper-airplane">{{ __('Enviar convite') }}</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</section>
