@props(['company'])

{{-- Certificado A1 da própria empresa: dados do atual e envio de um novo (só quem gerencia a empresa). --}}
@php($cert = $company->activeCertificate)

@if ($cert)
    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-zinc-500">{{ __('Titular') }}</dt><dd>{{ $cert->titular }}</dd>
        <dt class="text-zinc-500">{{ __('CNPJ') }}</dt><dd class="font-mono">{{ App\Support\Cnpj::from($cert->cnpj_certificado)->formatted() }}</dd>
        <dt class="text-zinc-500">{{ __('Validade') }}</dt><dd>{{ $cert->valid_from->format('d/m/Y') }} – {{ $cert->valid_to->format('d/m/Y') }}</dd>
        <dt class="text-zinc-500">{{ __('Situação') }}</dt><dd><x-certificate-badge :certificate="$cert" /></dd>
    </dl>
@else
    <flux:text>{{ $company->buscaPeloContador() ? __('Nenhum certificado da empresa enviado.') : __('Nenhum certificado enviado. A sincronização só começa após o envio.') }}</flux:text>
@endif

@can('manageCertificate', $company)
    <flux:separator />

    <form method="POST" action="{{ route('companies.certificates.store', $company) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <flux:input type="file" name="pfx" accept=".pfx,.p12" :label="$cert ? __('Trocar certificado (.pfx)') : __('Arquivo do certificado (.pfx)')" required />
        <flux:input type="password" name="password" :label="__('Senha do certificado')" required autocomplete="off" viewable />

        <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Enviar certificado') }}</flux:button>
    </form>
@endcan
