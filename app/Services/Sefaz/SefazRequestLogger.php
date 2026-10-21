<?php

namespace App\Services\Sefaz;

use App\Enums\SefazServico;
use App\Models\Company;
use App\Models\SefazRequest;
use Illuminate\Support\Str;

class SefazRequestLogger
{
    public function log(Company $company, SefazServico $servico, float $started, RetDistDFe $ret, ?string $ultNsuEnviado = null, ?string $chave = null): SefazRequest
    {
        return $company->sefazRequests()->create([
            'servico' => $servico,
            'tp_amb' => $company->ambiente->value,
            'cstat' => $ret->cStat,
            'xmotivo' => Str::limit($ret->xMotivo, 250),
            'ult_nsu_enviado' => $ultNsuEnviado,
            'ult_nsu_retornado' => $ret->ultNsu,
            'max_nsu' => $ret->maxNsu,
            'chave' => $chave,
            'documentos' => count($ret->documents),
            'duracao_ms' => $this->elapsed($started),
        ]);
    }

    public function logError(Company $company, SefazServico $servico, float $started, string $error, ?string $ultNsuEnviado = null, ?string $chave = null): SefazRequest
    {
        return $company->sefazRequests()->create([
            'servico' => $servico,
            'tp_amb' => $company->ambiente->value,
            'ult_nsu_enviado' => $ultNsuEnviado,
            'chave' => $chave,
            'duracao_ms' => $this->elapsed($started),
            'erro' => Str::limit($error, 250),
        ]);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
