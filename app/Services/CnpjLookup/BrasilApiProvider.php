<?php

namespace App\Services\CnpjLookup;

use App\Support\Cnpj;
use Illuminate\Support\Facades\Http;

class BrasilApiProvider implements CnpjProvider
{
    public function find(Cnpj $cnpj): ?CompanyLookupData
    {
        $response = Http::acceptJson()->timeout(8)->get("https://brasilapi.com.br/api/cnpj/v1/{$cnpj->value}");

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return new CompanyLookupData(
            razaoSocial: (string) $response->json('razao_social'),
            nomeFantasia: $response->json('nome_fantasia') ?: null,
            uf: $response->json('uf') ?: null,
            situacao: $response->json('descricao_situacao_cadastral') ?: null,
            source: 'BrasilAPI',
        );
    }
}
