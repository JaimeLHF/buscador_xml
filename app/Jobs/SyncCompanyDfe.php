<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Sefaz\DistribuicaoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCompanyDfe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Nunca reenviar automaticamente: uma nova tentativa fora de hora pode gerar consumo indevido. */
    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public Company $company)
    {
        $this->onQueue(config('sefaz.queue'));
    }

    /** Evita empilhar jobs da mesma empresa; o lock por CNPJ fica no DistribuicaoService. */
    public function uniqueId(): string
    {
        return (string) $this->company->id;
    }

    public function handle(DistribuicaoService $service): void
    {
        $service->sync($this->company);
    }
}
