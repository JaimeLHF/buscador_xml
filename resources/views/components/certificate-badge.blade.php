@props(['certificate'])

@if (! $certificate)
    <flux:badge size="sm" color="zinc">{{ __('Sem certificado') }}</flux:badge>
@elseif ($certificate->isExpired())
    <flux:badge size="sm" color="red">{{ __('Vencido') }}</flux:badge>
@elseif ($certificate->daysUntilExpiration() <= 30)
    <flux:badge size="sm" color="amber">{{ __('Vence em :d dias', ['d' => $certificate->daysUntilExpiration()]) }}</flux:badge>
@else
    <flux:badge size="sm" color="green">{{ __('Válido até :date', ['date' => $certificate->valid_to->format('d/m/Y')]) }}</flux:badge>
@endif
