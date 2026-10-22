<?php

namespace App\Enums;

enum DocumentoTipo: string
{
    case Nfe = 'nfe';
    case Cte = 'cte';

    /** Serviço da SEFAZ usado na distribuição por NSU deste tipo (cada um tem seu próprio limite de consultas). */
    public function servicoDistribuicao(): SefazServico
    {
        return match ($this) {
            self::Nfe => SefazServico::DistNsu,
            self::Cte => SefazServico::DistNsuCte,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Cte => 'CT-e',
        };
    }
}
