<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;

final readonly class CertificateData
{
    public function __construct(
        public string $pfx,
        public string $titular,
        public string $cnpj,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validTo,
        public string $fingerprint,
    ) {}
}
