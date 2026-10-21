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

new #[Title('Membros')] class extends Component {
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

        if ($member->current_organization_id === $this->organization->id) {
            $member->forceFill(['current_organization_id' => null])->save();
        }

        unset($this->members);
        Flux::toast(text: __('Membro removido.'));
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

<section class="w-full max-w-4xl space-y-8">
    <flux:heading size="xl">{{ __('Membros de :org', ['org' => $this->organization->name]) }}</flux:heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column>{{ __('E-mail') }}</flux:table.column>
            <flux:table.column>{{ __('Papel') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->members as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell>{{ $member->name }}</flux:table.cell>
                    <flux:table.cell>{{ $member->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($this->canManage && $member->pivot->role !== OrganizationRole::Owner)
                            <flux:select size="sm" wire:change="changeRole({{ $member->id }}, $event.target.value)" class="max-w-40">
                                @foreach ([OrganizationRole::Admin, OrganizationRole::Member] as $r)
                                    <flux:select.option :value="$r->value" :selected="$member->pivot->role === $r">{{ $r->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            {{ $member->pivot->role->label() }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage && $member->pivot->role !== OrganizationRole::Owner && $member->id !== auth()->id())
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $member->id }})" wire:confirm="{{ __('Remover :name da organização?', ['name' => $member->name]) }}" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->canManage)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Convidar') }}</flux:heading>

            <form wire:submit="invite" class="flex flex-wrap items-end gap-4">
                <flux:input wire:model="email" type="email" :label="__('E-mail')" required class="min-w-64 flex-1" />
                <flux:select wire:model="role" :label="__('Papel')" class="max-w-48">
                    <flux:select.option value="member">{{ OrganizationRole::Member->label() }}</flux:select.option>
                    <flux:select.option value="admin">{{ OrganizationRole::Admin->label() }}</flux:select.option>
                </flux:select>
                <flux:button type="submit" variant="primary">{{ __('Enviar convite') }}</flux:button>
            </form>

            @if ($this->invitations->isNotEmpty())
                <flux:separator />
                @foreach ($this->invitations as $inv)
                    <div class="flex items-center justify-between text-sm" wire:key="inv-{{ $inv->id }}">
                        <span>{{ $inv->email }} · {{ $inv->role->label() }} · {{ __('expira em :d', ['d' => Fmt::date($inv->expires_at)]) }}</span>
                        <flux:button size="sm" variant="ghost" wire:click="revoke({{ $inv->id }})">{{ __('Cancelar') }}</flux:button>
                    </div>
                @endforeach
            @endif
        </flux:card>
    @endif
</section>
