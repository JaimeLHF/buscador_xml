<?php

namespace App\Enums;

enum ExportFormato: string
{
    case Zip = 'zip';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Zip => 'XMLs (ZIP)',
            self::Csv => 'Planilha (CSV)',
        };
    }
}
