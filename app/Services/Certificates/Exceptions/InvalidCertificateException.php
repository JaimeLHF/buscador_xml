<?php

namespace App\Services\Certificates\Exceptions;

use RuntimeException;

/** Mensagens seguras para exibir ao usuário: nunca incluem senha ou conteúdo do certificado. */
class InvalidCertificateException extends RuntimeException
{
    public static function wrongPassword(): self
    {
        return new self('Senha do certificado incorreta.');
    }

    public static function unreadable(): self
    {
        return new self('Não foi possível ler o arquivo. Envie um certificado A1 no formato .pfx ou .p12.');
    }

    public static function withoutCnpj(): self
    {
        return new self('O certificado não é um e-CNPJ (não contém CNPJ do titular).');
    }

    public static function withoutDocumento(): self
    {
        return new self('O certificado não é um e-CNPJ nem um e-CPF (não contém CNPJ nem CPF do titular).');
    }

    public static function expired(): self
    {
        return new self('O certificado está vencido.');
    }

    public static function notYetValid(): self
    {
        return new self('O certificado ainda não está dentro do período de validade.');
    }

    public static function cnpjMismatch(): self
    {
        return new self('A raiz do CNPJ do certificado não corresponde à da empresa.');
    }
}
