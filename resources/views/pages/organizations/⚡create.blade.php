<?php

use App\Actions\Organizations\CreateOrganization;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nova organização')] class extends Component {
    public string $name = '';

    public function save(CreateOrganization $action): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']], attributes: ['name' => 'nome']);

        $action->handle(Auth::user(), $this->name);

        $this->redirectRoute('companies.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-lg space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Nova organização') }}</flux:heading>
        <flux:subheading>{{ __('Escritório ou cliente que agrupa empresas e usuários.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Nome')" required autofocus />

        <flux:button variant="primary" type="submit">{{ __('Criar') }}</flux:button>
    </form>
</section>
