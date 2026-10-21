<?php

namespace App\Enums;

enum DocumentoTipo: string
{
    case Nfe = 'nfe';
    case Cte = 'cte';

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Cte => 'CT-e',
        };
    }
}
