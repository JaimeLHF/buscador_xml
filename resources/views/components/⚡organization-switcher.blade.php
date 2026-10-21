<?php

use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function organizations()
    {
        return Auth::user()->organizations()->orderBy('name')->get();
    }

    public function switchTo(int $organizationId): void
    {
        Auth::user()->switchOrganization(Organization::findOrFail($organizationId));

        $this->redirectRoute('companies.index', navigate: true);
    }
}; ?>

<flux:dropdown position="bottom" align="start">
    <flux:button variant="ghost" icon="building-office-2" icon:trailing="chevrons-up-down" class="w-full justify-between! truncate">
        {{ Auth::user()->currentOrganization?->name ?? __('Organização') }}
    </flux:button>

    <flux:menu>
        <flux:menu.radio.group>
            @foreach ($this->organizations as $organization)
                <flux:menu.radio wire:click="switchTo({{ $organization->id }})" :checked="$organization->id === Auth::user()->current_organization_id">
                    {{ $organization->name }}
                </flux:menu.radio>
            @endforeach
        </flux:menu.radio.group>

        <flux:menu.separator />

        <flux:menu.item icon="plus" :href="route('organizations.create')" wire:navigate>{{ __('Nova organização') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
