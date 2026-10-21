<?php

namespace App\Services\Certificates;

use App\Services\Certificates\Exceptions\InvalidCertificateException;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\CertificateException;

class CertificateInspector
{
    public function __construct(private PfxNormalizer $normalizer) {}

    public function inspect(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password): CertificateData
    {
        $normalized = $this->normalizer->normalize($pfx, $password);

        try {
            $certificate = Certificate::readPfx($normalized, $password);
        } catch (CertificateException) {
            throw InvalidCertificateException::unreadable();
        }

        $cnpj = $certificate->getCnpj();

        // Apesar do PHPDoc, o sped-common retorna null quando o OID de CNPJ não existe (e-CPF).
        if (blank($cnpj) || ! Cnpj::isValid($cnpj)) {
            throw InvalidCertificateException::withoutCnpj();
        }

        return new CertificateData(
            pfx: $normalized,
            titular: $this->titular($certificate->getCompanyName()),
            cnpj: Cnpj::normalize($cnpj),
            validFrom: CarbonImmutable::instance($certificate->getValidFrom()),
            validTo: CarbonImmutable::instance($certificate->getValidTo()),
            fingerprint: (string) openssl_x509_fingerprint((string) $certificate->publicKey, 'sha256'),
        );
    }

    /** O CN de e-CNPJ ICP-Brasil vem como "RAZAO SOCIAL:CNPJ". */
    private function titular(string $commonName): string
    {
        return trim(explode(':', $commonName)[0]);
    }
}
