<?php

namespace App\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Processing => 'Gerando',
            self::Done => 'Pronto',
            self::Failed => 'Falhou',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
