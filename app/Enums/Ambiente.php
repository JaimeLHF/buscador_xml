<?php

namespace App\Enums;

/** tpAmb da SEFAZ. */
enum Ambiente: int
{
    case Producao = 1;
    case Homologacao = 2;

    public function label(): string
    {
        return match ($this) {
            self::Producao => 'Produção',
            self::Homologacao => 'Homologação',
        };
    }
}
