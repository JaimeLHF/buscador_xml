{{--
    Esqueletos de página mostrados enquanto o wire:navigate busca a próxima tela.
    O resources/js/app.js escolhe o tipo pela URL de destino e marca #conteudo[data-carregando].
--}}
<div class="esqueleto-pagina mx-auto w-full max-w-5xl space-y-6" data-tipo="notas" aria-hidden="true">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <span class="osso block h-7 w-52"></span>
            <span class="osso block h-3.5 w-80 max-w-full"></span>
        </div>
        <div class="flex gap-2">
            <span class="osso h-10 w-32"></span>
            <span class="osso h-10 w-32 max-sm:hidden"></span>
            <span class="osso h-10 w-28 max-sm:hidden"></span>
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        @for ($i = 0; $i < 5; $i++)
            <div class="space-y-2.5 border border-zinc-200 p-4 dark:border-zinc-700 {{ $i > 1 ? 'max-sm:hidden' : '' }}">
                <span class="osso block h-3 w-14"></span>
                <span class="osso block h-6 w-20"></span>
            </div>
        @endfor
    </div>
    <div class="flex gap-2">
        <span class="osso h-10 flex-1"></span>
        <span class="osso h-10 w-28"></span>
    </div>
    <div class="flex gap-3">
        <span class="osso h-9 w-44"></span>
        <span class="osso h-9 w-64 max-sm:hidden"></span>
    </div>
    <div class="border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800"><span class="osso block h-3 w-24"></span></div>
        <x-esqueleto.linhas :n="7" />
    </div>
</div>

<div class="esqueleto-pagina w-full space-y-6" data-tipo="lista" aria-hidden="true">
    <div class="space-y-2">
        <span class="osso block h-7 w-44"></span>
        <span class="osso block h-3.5 w-96 max-w-full"></span>
    </div>
    <div class="flex gap-2">
        <span class="osso h-10 flex-1"></span>
        <span class="osso h-10 w-40"></span>
    </div>
    <div class="border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-700"><span class="osso block h-3 w-1/2"></span></div>
        <x-esqueleto.linhas :n="8" />
    </div>
</div>

<div class="esqueleto-pagina w-full space-y-8" data-tipo="detalhe" aria-hidden="true">
    <div class="space-y-3">
        <span class="osso block h-3.5 w-24"></span>
        <span class="osso block h-8 w-96 max-w-full"></span>
        <span class="osso block h-3.5 w-64 max-w-full"></span>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @for ($i = 0; $i < 3; $i++)
            <div class="space-y-3 border border-zinc-200 p-5 dark:border-zinc-700">
                <span class="osso block h-3 w-20"></span>
                <span class="osso block h-6 w-32"></span>
                <span class="osso block h-3 w-3/4"></span>
            </div>
        @endfor
    </div>
    <div class="space-y-4 border border-zinc-200 p-5 dark:border-zinc-700">
        <span class="osso block h-5 w-40"></span>
        @for ($i = 0; $i < 5; $i++)
            <div class="flex justify-between gap-6">
                <span class="osso h-3.5 w-32"></span>
                <span class="osso h-3.5" style="width: {{ [40, 28, 46, 34, 22][$i] }}%"></span>
            </div>
        @endfor
    </div>
    <div class="border border-zinc-200 dark:border-zinc-700">
        <x-esqueleto.linhas :n="4" />
    </div>
</div>

<div class="esqueleto-pagina mx-auto w-full max-w-2xl space-y-8" data-tipo="formulario" aria-hidden="true">
    <div class="space-y-2">
        <span class="osso block h-7 w-56"></span>
        <span class="osso block h-3.5 w-80 max-w-full"></span>
    </div>
    @for ($i = 0; $i < 5; $i++)
        <div class="space-y-2">
            <span class="osso block h-3.5 w-28"></span>
            <span class="osso block h-10 w-full"></span>
        </div>
    @endfor
    <div class="flex justify-end gap-2">
        <span class="osso h-10 w-24"></span>
        <span class="osso h-10 w-28"></span>
    </div>
</div>
