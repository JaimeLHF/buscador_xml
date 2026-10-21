<?php

namespace App\Enums;

enum DfeSchema: string
{
    case ResNFe = 'resNFe';
    case ProcNFe = 'procNFe';
    case ResEvento = 'resEvento';
    case ProcEventoNFe = 'procEventoNFe';

    /** O atributo schema do docZip vem como "resNFe_v1.01.xsd". */
    public static function fromDocZip(string $schema): ?self
    {
        return self::tryFrom(strtok($schema, '_') ?: '');
    }

    public function isEvent(): bool
    {
        return $this === self::ResEvento || $this === self::ProcEventoNFe;
    }

    public function isComplete(): bool
    {
        return $this === self::ProcNFe || $this === self::ProcEventoNFe;
    }
}
