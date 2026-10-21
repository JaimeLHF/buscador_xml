<?php

namespace App\Services\Sefaz\Exceptions;

use RuntimeException;

class SefazException extends RuntimeException
{
    public static function productionBlocked(): self
    {
        return new self('Consultas em produção estão desabilitadas (SEFAZ_ALLOW_PRODUCTION=false).');
    }

    public static function withoutCertificate(): self
    {
        return new self('Empresa sem certificado ativo e válido.');
    }

    public static function invalidResponse(): self
    {
        return new self('Resposta da SEFAZ em formato inesperado.');
    }

    public static function rateLimited(): self
    {
        return new self('Limite de 20 consultas por chave/NSU na última hora atingido para este CNPJ.');
    }
}
