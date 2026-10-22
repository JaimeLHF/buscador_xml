<?php

namespace App\Services\SefSc;

use App\Models\Certificate;
use App\Models\Company;

/** Fronteira com o web service NfeDownloadContab da SEF-SC. Retorna o XML bruto; os testes substituem esta interface. */
interface SefScGateway
{
    public function distribuicao(Certificate $certificate, Company $company, string $ultNsu): string;
}
