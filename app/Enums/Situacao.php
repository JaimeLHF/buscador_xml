<?php

namespace App\Enums;

enum Situacao: string
{
    case Autorizada = 'autorizada';
    case Cancelada = 'cancelada';
    case Denegada = 'denegada';

    /** cSitNFe do resNFe: 1 = autorizada, 2 = denegada, 3 = cancelada. */
    public static function fromCSitNFe(string $code): self
    {
        return match ($code) {
            '2' => self::Denegada,
            '3' => self::Cancelada,
            default => self::Autorizada,
        };
    }

    /** cStat do protNFe: 110, 301, 302 e 303 são denegações. */
    public static function fromProtocolo(string $cstat): self
    {
        return in_array($cstat, ['110', '301', '302', '303'], true) ? self::Denegada : self::Autorizada;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
