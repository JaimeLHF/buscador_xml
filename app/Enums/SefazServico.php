<?php

namespace App\Enums;

enum SefazServico: string
{
    case DistNsu = 'dist_nsu';
    case ConsNsu = 'cons_nsu';
    case ConsChave = 'cons_chave';
    case Manifestacao = 'manifestacao';

    /** consChNFe e consNSU compartilham o limite de 20 consultas/hora por CNPJ. */
    public function isRateLimited(): bool
    {
        return $this === self::ConsNsu || $this === self::ConsChave;
    }
}
