@props(['certificate' => null, 'empresas' => 0, 'canManage' => false])

{{--
    Painel do certificado do escritório (modo "certificado do contador", só SC).
    Fechado mostra só o essencial: o que é e se está pronto. Aberto mostra os passos e o envio do arquivo.
--}}
@php
    $erroArquivo = session('errors')?->first('pfx');
    $erroSenha = session('errors')?->first('password');
    // Abre sozinho quando há algo a resolver: erro no envio ou empresa esperando pelo certificado.
    $abrir = $erroArquivo || $erroSenha || ($empresas > 0 && ! $certificate);
    [$statusTexto, $statusCor] = match (true) {
        $certificate === null && $empresas > 0 => [__('Falta enviar'), 'red'],
        $certificate === null => [__('Não configurado'), 'zinc'],
        $certificate->isExpired() => [__('Vencido'), 'red'],
        $certificate->daysUntilExpiration() <= 30 => [__('Vence em :d dias', ['d' => $certificate->daysUntilExpiration()]), 'amber'],
        default => [__('Pronto'), 'green'],
    };
@endphp

<section x-data="{ aberto: @js((bool) $abrir) }" class="border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40">
    {{-- Cabeçalho inteiro clicável. --}}
    <button type="button" x-on:click="aberto = ! aberto" x-bind:aria-expanded="aberto" aria-controls="painel-escritorio"
        class="flex w-full items-center gap-4 px-4 py-3 text-start transition-colors hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-inset dark:hover:bg-white/[0.03]">
        <span class="flex size-10 shrink-0 items-center justify-center border border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300" aria-hidden="true">
            <flux:icon.key variant="mini" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-baseline gap-x-2 font-medium text-zinc-900 dark:text-white">
                {{ __('Certificado do contador') }}
                <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('empresas de SC') }}</span>
            </span>
            {{-- No celular o status vai para baixo do título, para o título não espremer. --}}
            <flux:badge size="sm" :color="$statusCor" class="mt-1 sm:hidden">{{ $statusTexto }}</flux:badge>
            <span class="block truncate text-sm text-zinc-500 max-sm:hidden dark:text-zinc-400">
                @if ($certificate)
                    {{ $certificate->titular }} · {{ trans_choice(':n empresa usando|:n empresas usando', $empresas, ['n' => $empresas]) }}
                @else
                    {{ __('Um único certificado do escritório busca as notas de todas as empresas de SC, sem o certificado de cada uma.') }}
                @endif
            </span>
        </span>

        <flux:badge size="sm" :color="$statusCor" class="shrink-0 max-sm:hidden">{{ $statusTexto }}</flux:badge>
        <flux:icon.chevron-down variant="mini" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="aberto && 'rotate-180'" />
    </button>

    <div id="painel-escritorio" x-show="aberto" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
        <div class="grid md:grid-cols-2 md:divide-x md:divide-zinc-200 dark:md:divide-zinc-700">
            {{-- Esquerda: como funciona, ou os dados do certificado atual. --}}
            <div class="space-y-4 p-4">
                @if ($certificate)
                    <x-campos :itens="[
                        __('Titular') => $certificate->titular,
                        strlen($certificate->cnpj_certificado) === 11 ? __('CPF') : __('CNPJ') => $certificate->documentoFormatado(),
                        __('Validade') => $certificate->valid_from->format('d/m/Y').' – '.$certificate->valid_to->format('d/m/Y'),
                        __('Empresas usando') => trans_choice(':n empresa|:n empresas', $empresas, ['n' => $empresas]),
                    ]" />
                @endif

                <div>
                    <p class="mb-3 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Como funciona') }}</p>
                    <ol class="space-y-3 text-sm">
                        @foreach ([
                            [__('Envie o certificado do escritório'), __('e-CNPJ do escritório contábil ou e-CPF do contador, arquivo A1 (.pfx).')],
                            [__('Confira o cadastro na SEF-SC'), __('O titular do certificado precisa constar como contador de cada empresa. Sem isso a SEF-SC recusa a busca.')],
                            [__('Marque as empresas'), __('Em cada empresa de SC: Editar → "Como buscar as notas" → Certificado do contador.')],
                        ] as $i => [$titulo, $texto])
                            <li class="flex gap-3">
                                <span @class([
                                    'flex size-6 shrink-0 items-center justify-center border text-xs font-semibold tabular-nums',
                                    'border-emerald-500 bg-emerald-500 text-white dark:border-emerald-600 dark:bg-emerald-600' => $i === 0 && $certificate,
                                    'border-zinc-300 text-zinc-600 dark:border-zinc-600 dark:text-zinc-300' => ! ($i === 0 && $certificate),
                                ])>
                                    @if ($i === 0 && $certificate)
                                        <flux:icon.check variant="micro" />
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </span>
                                <span>
                                    <span class="block font-medium text-zinc-900 dark:text-white">{{ $titulo }}</span>
                                    <span class="block text-zinc-500 dark:text-zinc-400">{{ $texto }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            {{-- Direita: envio do arquivo. --}}
            <div class="bg-zinc-50 p-4 dark:bg-white/[0.02]">
                @if ($canManage)
                    <form method="POST" action="{{ route('office-certificate.store') }}" enctype="multipart/form-data" class="space-y-4" x-data="{ arquivo: '' }">
                        @csrf
                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $certificate ? __('Trocar certificado') : __('Enviar certificado') }}</p>

                        {{-- Campo de arquivo próprio: o input fica por cima, transparente, e continua acessível e com validação nativa. --}}
                        <div>
                            <label for="pfx-escritorio" class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Arquivo do certificado') }}</label>
                            <div @class([
                                'relative flex items-center gap-3 border border-dashed px-4 py-4 transition-colors focus-within:ring-2 focus-within:ring-zinc-400 hover:border-zinc-400 dark:hover:border-zinc-500',
                                'border-red-500' => $erroArquivo,
                                'border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800' => ! $erroArquivo,
                            ])>
                                <flux:icon.document-arrow-up class="size-6 shrink-0 text-zinc-400" />
                                <span class="min-w-0 text-sm">
                                    <span class="block truncate font-medium text-zinc-900 dark:text-white" x-text="arquivo || '{{ __('Clique para escolher o arquivo') }}'">{{ __('Clique para escolher o arquivo') }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('.pfx ou .p12, até 64 KB') }}</span>
                                </span>
                                <input id="pfx-escritorio" type="file" name="pfx" accept=".pfx,.p12" required
                                    x-on:change="arquivo = $event.target.files[0]?.name ?? ''"
                                    class="absolute inset-0 size-full cursor-pointer opacity-0" />
                            </div>
                            @if ($erroArquivo)
                                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $erroArquivo }}</p>
                            @endif
                        </div>

                        <div>
                            <flux:input type="password" name="password" :label="__('Senha do certificado')" required autocomplete="off" viewable />
                            @if ($erroSenha)
                                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $erroSenha }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ $certificate ? __('Trocar certificado') : __('Enviar certificado') }}</flux:button>
                            <span class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                <flux:icon.lock-closed variant="micro" /><span>{{ __('Guardado criptografado') }}</span>
                            </span>
                        </div>
                    </form>
                @else
                    <flux:text size="sm">{{ __('Somente donos e administradores da organização podem enviar o certificado do escritório.') }}</flux:text>
                @endif
            </div>
        </div>
    </div>
</section>
