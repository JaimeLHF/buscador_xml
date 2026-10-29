<?php

namespace App\Enums;

enum ConferenciaSped: string
{
    case Confere = 'confere';
    case Diverge = 'diverge';

    public function label(): string
    {
        return match ($this) {
            self::Confere => __('Confere com o SPED'),
            self::Diverge => __('Diverge do SPED'),
        };
    }
}
