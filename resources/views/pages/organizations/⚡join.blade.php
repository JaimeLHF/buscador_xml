<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Convite')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    #[Locked]
    public string $token = '';

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        if ($this->organization() === null) {
            return;
        }

        // Quem escolher "já tenho conta" volta para cá depois do login.
        if (Auth::guest()) {
            session()->put('url.intended', route('join', $token));
        } elseif (Auth::user()->belongsToOrganization($this->organization())) {
            Auth::user()->switchOrganization($this->organization());
            $this->redirectRoute('documents.index');
        }
    }

    public function organization(): ?Organization
    {
        return Organization::findByJoinToken($this->token);
    }

    public function register(): void
    {
        abort_unless(Auth::guest(), 403);
        $organization = $this->organization() ?? abort(404);

        $this->validate([...$this->profileRules(), 'password' => $this->passwordRules()]);

        $user = DB::transaction(function () use ($organization): User {
            $user = User::create(['name' => $this->name, 'email' => $this->email, 'password' => $this->password]);
            $this->addTo($organization, $user);

            return $user;
        });

        Auth::login($user);
        session()->regenerate();
        session()->forget('url.intended');

        $this->redirectRoute('documents.index');
    }

    public function join(): void
    {
        $user = Auth::user() ?? abort(403);
        $organization = $this->organization() ?? abort(404);

        DB::transaction(fn () => $this->addTo($organization, $user));

        $this->redirectRoute('documents.index');
    }

    private function addTo(Organization $organization, User $user): void
    {
        // O link é compartilhável e pode vazar: entra sempre como membro, e quem já é membro mantém o papel.
        if (! $user->belongsToOrganization($organization)) {
            $organization->addMember($user, OrganizationRole::Member);
        }

        $user->switchOrganization($organization);
    }
}; ?>

<div class="flex flex-col gap-6">
    @php($organization = $this->organization())

    @if ($organization === null)
        <x-auth-header :title="__('Convite inválido')" :description="__('Este link de convite não existe mais. Peça um novo link a quem convidou você.')" />
    @elseif (auth()->check())
        <x-auth-header :title="__('Entrar em :org', ['org' => $organization->name])" :description="__('Você está conectado como :email.', ['email' => auth()->user()->email])" />

        <flux:button variant="primary" class="w-full" wire:click="join">{{ __('Entrar na organização') }}</flux:button>
    @else
        <x-auth-header :title="__('Entrar em :org', ['org' => $organization->name])" :description="__('Crie sua conta para ver as notas da organização.')" />

        <form wire:submit="register" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" :placeholder="__('Full name')" />

            <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="email" placeholder="email@example.com" />

            <flux:input wire:model="password" :label="__('Password')" type="password" required autocomplete="new-password" :placeholder="__('Password')" viewable />

            <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" :placeholder="__('Confirm password')" viewable />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Criar conta e entrar') }}</flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link class="inline-block py-3" :href="route('login')">{{ __('Log in') }}</flux:link>
        </div>
    @endif
</div>
