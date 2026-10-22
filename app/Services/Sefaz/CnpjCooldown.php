<?php

namespace App\Services\Sefaz;

use App\Enums\DocumentoTipo;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * O bloqueio por consumo indevido é por CNPJ na SEFAZ, não por cadastro aqui. Se o mesmo CNPJ estiver em
 * mais de uma organização, este registro impede que um cadastro consulte enquanto o outro está em espera.
 */
class CnpjCooldown
{
    public function until(Company $company, DocumentoTipo $tipo = DocumentoTipo::Nfe): ?CarbonImmutable
    {
        $timestamp = Cache::get($this->key($company, $tipo));

        return $timestamp && $timestamp > now()->getTimestamp() ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    public function set(Company $company, CarbonImmutable $until, DocumentoTipo $tipo = DocumentoTipo::Nfe): void
    {
        $current = $this->until($company, $tipo);

        if ($current === null || $until->greaterThan($current)) {
            Cache::put($this->key($company, $tipo), $until->getTimestamp(), $until);
        }
    }

    /** NF-e e CT-e são serviços distintos na SEFAZ, cada um com seu controle de consumo indevido. */
    private function key(Company $company, DocumentoTipo $tipo): string
    {
        $sufixo = $tipo === DocumentoTipo::Nfe ? '' : ":{$tipo->value}";

        return "sefaz:cooldown:{$company->ambiente->value}:{$company->cnpj}{$sufixo}";
    }
}
