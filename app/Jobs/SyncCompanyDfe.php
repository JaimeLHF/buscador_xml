<?php

namespace App\Jobs;

use App\Enums\DocumentoTipo;
use App\Models\Company;
use App\Services\Sefaz\DistribuicaoService;
use App\Services\Sefaz\SyncOutcome;
use App\Services\Sefaz\SyncProgress;
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

    public function __construct(public Company $company, public DocumentoTipo $tipo = DocumentoTipo::Nfe)
    {
        $this->onQueue(config('sefaz.queue'));
    }

    /** Evita empilhar jobs da mesma empresa e tipo; o lock por CNPJ fica no DistribuicaoService. */
    public function uniqueId(): string
    {
        return $this->tipo === DocumentoTipo::Nfe ? (string) $this->company->id : "{$this->company->id}:{$this->tipo->value}";
    }

    public function handle(DistribuicaoService $service): void
    {
        try {
            $outcome = $service->sync($this->company, $this->tipo, untilDone: true);

            SyncProgress::done($this->company->id, $outcome);
        } catch (\Throwable $e) {
            SyncProgress::done($this->company->id, SyncOutcome::Failed, $e->getMessage());
            throw $e;
        }
    }
}
