{{-- Bloco de informação com título em faixa própria: separa visualmente cada grupo de dados da nota. --}}
@props(['titulo'])

<section {{ $attributes->class('border border-zinc-200 dark:border-zinc-700') }}>
    <h2 class="border-b border-zinc-200 bg-zinc-100 px-3 py-2 text-xs font-semibold tracking-wide text-zinc-700 uppercase dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $titulo }}</h2>
    {{ $slot }}
</section>
