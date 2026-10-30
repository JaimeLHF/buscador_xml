@props(['n' => 8])

{{-- Linhas cinzas que piscam no lugar da lista enquanto o filtro ou a página carrega. --}}
<div {{ $attributes->class('linhas-esqueleto') }} aria-hidden="true">
    @for ($i = 0; $i < $n; $i++)
        <div class="flex items-center gap-4 border-b border-zinc-100 px-4 py-3.5 last:border-b-0 dark:border-zinc-800">
            <span class="osso h-4 w-10 shrink-0"></span>
            <span class="min-w-0 flex-1 space-y-1.5">
                <span class="osso block h-3.5" style="width: {{ [72, 58, 84, 64, 76, 52, 68, 80][$i % 8] }}%"></span>
                <span class="osso block h-2.5 w-1/3 md:hidden"></span>
            </span>
            <span class="osso h-3.5 w-28 shrink-0 max-md:hidden"></span>
            <span class="osso h-3.5 w-20 shrink-0"></span>
            <span class="osso h-3.5 w-20 shrink-0 max-md:hidden"></span>
            <span class="osso h-3.5 w-12 shrink-0 max-md:hidden"></span>
        </div>
    @endfor
</div>
