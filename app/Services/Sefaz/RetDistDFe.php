<?php

namespace App\Services\Sefaz;

use App\Enums\CStat;

final readonly class RetDistDFe
{
    /**
     * @param  list<DocZip>  $documents
     */
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public ?string $ultNsu,
        public ?string $maxNsu,
        public array $documents,
    ) {}

    public function is(CStat $cStat): bool
    {
        return $this->cStat === $cStat->value;
    }

    public function hasMore(): bool
    {
        return $this->ultNsu !== null && $this->maxNsu !== null && $this->ultNsu < $this->maxNsu;
    }
}
