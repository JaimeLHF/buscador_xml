{{-- Lista rótulo → valor, densa, para os dados da nota. Valores vazios não aparecem. --}}
@props(['itens' => []])

<dl {{ $attributes->class('grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-4 gap-y-1.5 text-sm') }}>
    @foreach ($itens as $rotulo => $valor)
        @continue($valor === null || $valor === '')
        <dt class="text-zinc-500 dark:text-zinc-400">{{ $rotulo }}</dt>
        <dd class="min-w-0 break-words text-zinc-900 dark:text-zinc-100">{{ $valor }}</dd>
    @endforeach
</dl>
