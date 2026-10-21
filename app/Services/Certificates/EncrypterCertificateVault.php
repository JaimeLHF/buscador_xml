<?php

namespace App\Services\Certificates;

use Illuminate\Contracts\Encryption\Encrypter;

final readonly class EncrypterCertificateVault implements CertificateVault
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        return $this->encrypter->encryptString($plain);
    }

    public function decrypt(string $payload): string
    {
        return $this->encrypter->decryptString($payload);
    }
}
