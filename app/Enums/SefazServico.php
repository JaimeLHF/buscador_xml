<?php

namespace App\Enums;

enum SefazServico: string
{
    case DistNsu = 'dist_nsu';
    case ConsNsu = 'cons_nsu';
    case ConsChave = 'cons_chave';
    case Manifestacao = 'manifestacao';
    case DistNsuCte = 'dist_nsu_cte';
    case DownloadSefSc = 'download_sef_sc';

    public function label(): string
    {
        return match ($this) {
            self::DistNsu => 'Distribuição (NSU)',
            self::ConsNsu => 'Consulta por NSU',
            self::ConsChave => 'Consulta por chave',
            self::Manifestacao => 'Manifestação',
            self::DistNsuCte => 'Distribuição CT-e (NSU)',
            self::DownloadSefSc => 'Download SEF-SC (contador)',
        };
    }

    /** consChNFe e consNSU compartilham o limite de 20 consultas/hora por CNPJ. */
    public function isRateLimited(): bool
    {
        return $this === self::ConsNsu || $this === self::ConsChave;
    }
}
