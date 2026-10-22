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

    /**
     * @param  bool  $aceitaCpf  certificado do escritório: pode ser o e-CPF do próprio contador
     */
    public function inspect(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, bool $aceitaCpf = false): CertificateData
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
            $cpf = $aceitaCpf ? preg_replace('/\D/', '', (string) $certificate->getCpf()) : '';

            if (strlen((string) $cpf) !== 11) {
                throw $aceitaCpf ? InvalidCertificateException::withoutDocumento() : InvalidCertificateException::withoutCnpj();
            }

            $cnpj = (string) $cpf;
        }

        return new CertificateData(
            pfx: $normalized,
            titular: $this->titular($certificate->getCompanyName()),
            cnpj: strlen($cnpj) === 11 ? $cnpj : Cnpj::normalize($cnpj),
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
