<?php

namespace App\Services\CnpjLookup;

use App\Support\Cnpj;
use Illuminate\Support\Facades\Http;

/** API pública do CNPJá (sem chave, ~5 consultas/minuto). Usada como reserva da BrasilAPI. */
class CnpjaOpenProvider implements CnpjProvider
{
    public function find(Cnpj $cnpj): ?CompanyLookupData
    {
        $response = Http::acceptJson()->timeout(8)->get("https://open.cnpja.com/office/{$cnpj->value}");

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return new CompanyLookupData(
            razaoSocial: (string) $response->json('company.name'),
            nomeFantasia: $response->json('alias') ?: null,
            uf: $response->json('address.state') ?: null,
            situacao: $response->json('status.text') ?: null,
            source: 'CNPJá',
        );
    }
}
