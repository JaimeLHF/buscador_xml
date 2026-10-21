<?php

namespace App\Services\Certificates;

/** Criptografia em repouso de material de certificado. Trocar a implementação para usar KMS. */
interface CertificateVault
{
    public function encrypt(string $plain): string;

    public function decrypt(string $payload): string;
}
