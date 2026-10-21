<?php

namespace App\Services\Sefaz\Exceptions;

use RuntimeException;

/** Mensagens seguras para exibir ao usuário. */
class ManifestacaoException extends RuntimeException
{
    public static function notAllowed(string $reason): self
    {
        return new self($reason);
    }

    public static function justificativaInvalida(): self
    {
        return new self('Informe uma justificativa entre 15 e 255 caracteres.');
    }

    public static function rejected(string $cStat, string $xMotivo): self
    {
        return new self("SEFAZ rejeitou o evento: {$cStat} - {$xMotivo}");
    }
}
