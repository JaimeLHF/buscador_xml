<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- Painel admin: layout próprio, sem seletor de empresa nem menus das organizações. --}}
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('admin.conexoes.index') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="flex items-center gap-2 border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold tracking-widest text-amber-800 uppercase dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-300">
                <flux:icon name="shield-check" variant="micro" />
                {{ __('Painel admin') }}
            </div>

            <flux:sidebar.nav class="nav-principal">
                <flux:sidebar.item icon="link" :href="route('admin.conexoes.index')" :current="request()->routeIs('admin.conexoes.*')" wire:navigate>
                    {{ __('Conexões') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav class="nav-principal">
                <flux:sidebar.item icon="arrow-uturn-left" :href="route('documents.index')" wire:navigate>
                    {{ __('Voltar ao FinderXML') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
        </flux:header>

        <flux:main id="conteudo" tabindex="-1" class="focus:outline-none">
            {{ $slot }}

            <x-esqueleto.pagina />
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
