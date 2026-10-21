<?php

namespace App\Services\CnpjLookup;

final readonly class CompanyLookupData
{
    public function __construct(
        public string $razaoSocial,
        public ?string $nomeFantasia,
        public ?string $uf,
        public ?string $situacao,
        public string $source,
    ) {}

    public function isActive(): bool
    {
        return $this->situacao === null || strcasecmp($this->situacao, 'ATIVA') === 0;
    }
}
