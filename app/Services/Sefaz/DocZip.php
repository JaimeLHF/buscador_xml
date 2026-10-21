<?php

namespace App\Services\Sefaz;

use App\Enums\DfeSchema;

final readonly class DocZip
{
    public function __construct(
        public string $nsu,
        public string $rawSchema,
        public ?DfeSchema $schema,
        public string $xml,
    ) {}
}
