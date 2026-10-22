<?php

namespace App\Enums;

enum DfeSchema: string
{
    case ResNFe = 'resNFe';
    case ProcNFe = 'procNFe';
    case ResEvento = 'resEvento';
    case ProcEventoNFe = 'procEventoNFe';
    case ProcCTe = 'procCTe';
    case ProcCTeOS = 'procCTeOS';
    case ProcEventoCTe = 'procEventoCTe';

    /** O atributo schema do docZip vem como "resNFe_v1.01.xsd". */
    public static function fromDocZip(string $schema): ?self
    {
        return self::tryFrom(strtok($schema, '_') ?: '');
    }

    public function isEvent(): bool
    {
        return in_array($this, [self::ResEvento, self::ProcEventoNFe, self::ProcEventoCTe], true);
    }

    public function isComplete(): bool
    {
        return in_array($this, [self::ProcNFe, self::ProcEventoNFe, self::ProcCTe, self::ProcCTeOS, self::ProcEventoCTe], true);
    }

    public function tipo(): DocumentoTipo
    {
        return in_array($this, [self::ProcCTe, self::ProcCTeOS, self::ProcEventoCTe], true) ? DocumentoTipo::Cte : DocumentoTipo::Nfe;
    }
}
