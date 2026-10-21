<?php

namespace App\Services\Sefaz;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * O bloqueio por consumo indevido é por CNPJ na SEFAZ, não por cadastro aqui. Se o mesmo CNPJ estiver em
 * mais de uma organização, este registro impede que um cadastro consulte enquanto o outro está em espera.
 */
class CnpjCooldown
{
    public function until(Company $company): ?CarbonImmutable
    {
        $timestamp = Cache::get($this->key($company));

        return $timestamp && $timestamp > now()->getTimestamp() ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    public function set(Company $company, CarbonImmutable $until): void
    {
        $current = $this->until($company);

        if ($current === null || $until->greaterThan($current)) {
            Cache::put($this->key($company), $until->getTimestamp(), $until);
        }
    }

    private function key(Company $company): string
    {
        return "sefaz:cooldown:{$company->ambiente->value}:{$company->cnpj}";
    }
}
