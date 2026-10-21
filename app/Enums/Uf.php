<?php

namespace App\Enums;

/** UF com o código IBGE usado pela SEFAZ (cUFAutor). */
enum Uf: string
{
    case AC = 'AC';
    case AL = 'AL';
    case AP = 'AP';
    case AM = 'AM';
    case BA = 'BA';
    case CE = 'CE';
    case DF = 'DF';
    case ES = 'ES';
    case GO = 'GO';
    case MA = 'MA';
    case MT = 'MT';
    case MS = 'MS';
    case MG = 'MG';
    case PA = 'PA';
    case PB = 'PB';
    case PR = 'PR';
    case PE = 'PE';
    case PI = 'PI';
    case RJ = 'RJ';
    case RN = 'RN';
    case RS = 'RS';
    case RO = 'RO';
    case RR = 'RR';
    case SC = 'SC';
    case SP = 'SP';
    case SE = 'SE';
    case TO = 'TO';

    public function ibge(): int
    {
        return match ($this) {
            self::RO => 11, self::AC => 12, self::AM => 13, self::RR => 14, self::PA => 15,
            self::AP => 16, self::TO => 17, self::MA => 21, self::PI => 22, self::CE => 23,
            self::RN => 24, self::PB => 25, self::PE => 26, self::AL => 27, self::SE => 28,
            self::BA => 29, self::MG => 31, self::ES => 32, self::RJ => 33, self::SP => 35,
            self::PR => 41, self::SC => 42, self::RS => 43, self::MS => 50, self::MT => 51,
            self::GO => 52, self::DF => 53,
        };
    }
}
