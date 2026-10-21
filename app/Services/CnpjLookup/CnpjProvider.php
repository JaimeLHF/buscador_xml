<?php

namespace App\Services\CnpjLookup;

use App\Support\Cnpj;

interface CnpjProvider
{
    /** Retorna null quando o CNPJ não existe; lança exceção em falha de rede/limite. */
    public function find(Cnpj $cnpj): ?CompanyLookupData;
}
