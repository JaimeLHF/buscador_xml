{{-- Tabela rótulo → valor com linhas separadas, para leitura rápida dos dados da nota. Valores vazios não aparecem. --}}
@props(['itens' => [], 'colunas' => 1])

@php
    $linhas = collect($itens)->reject(fn ($v) => $v === null || $v === '');
    // Em duas colunas, uma célula vazia completa a última linha para a grade não ficar com buraco.
    $sobra = $colunas === 2 && $linhas->count() % 2 === 1;
@endphp

<dl {{ $attributes->class(['grid gap-px bg-zinc-200 text-sm dark:bg-zinc-700', 'md:grid-cols-2' => $colunas === 2]) }}>
    @foreach ($linhas as $rotulo => $valor)
        <div class="grid grid-cols-[10rem_minmax(0,1fr)] bg-white max-sm:grid-cols-1 dark:bg-zinc-800">
            <dt class="bg-zinc-50 px-3 py-2 text-zinc-500 max-sm:pb-0 dark:bg-white/[0.03] dark:text-zinc-400">{{ $rotulo }}</dt>
            <dd class="px-3 py-2 break-words text-zinc-900 dark:text-zinc-100">{{ $valor }}</dd>
        </div>
    @endforeach
    @if ($sobra)
        <div class="bg-white max-md:hidden dark:bg-zinc-800" aria-hidden="true"></div>
    @endif
</dl>
