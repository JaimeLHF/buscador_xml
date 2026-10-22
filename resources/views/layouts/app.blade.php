<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main id="conteudo" tabindex="-1" class="focus:outline-none">
        @if (session('success'))
            <flux:callout variant="success" icon="check-circle" :heading="session('success')" class="mb-6" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="session('error')" class="mb-6" />
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
