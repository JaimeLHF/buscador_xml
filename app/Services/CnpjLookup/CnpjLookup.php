<?php

namespace App\Services\CnpjLookup;

use App\Support\Cnpj;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Consulta dados cadastrais públicos do CNPJ, tentando os provedores em ordem e com cache. */
class CnpjLookup
{
    /**
     * @param  list<CnpjProvider>  $providers
     */
    public function __construct(private array $providers) {}

    public function find(Cnpj $cnpj): ?CompanyLookupData
    {
        $key = "cnpj-lookup:{$cnpj->value}";

        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        foreach ($this->providers as $provider) {
            try {
                $data = $provider->find($cnpj);
            } catch (Throwable $e) {
                Log::warning('Falha na consulta de CNPJ', ['provider' => $provider::class, 'error' => $e->getMessage()]);

                continue;
            }

            // "Não encontrado" também é cacheado (por menos tempo) para não repetir chamadas.
            Cache::put($key, $data ?? false, $data ? now()->addDay() : now()->addHour());

            return $data;
        }

        return null;
    }
}
