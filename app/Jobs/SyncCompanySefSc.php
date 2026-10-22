<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Sefaz\SyncOutcome;
use App\Services\Sefaz\SyncProgress;
use App\Services\SefSc\SefScDistribuicaoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Busca pela SEF-SC (modo "certificado do contador"). Mesmas garantias do SyncCompanyDfe: sem reenvio automático. */
class SyncCompanySefSc implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public Company $company)
    {
        $this->onQueue(config('sefaz.queue'));
    }

    public function uniqueId(): string
    {
        return "{$this->company->id}:sef-sc";
    }

    public function handle(SefScDistribuicaoService $service): void
    {
        try {
            $outcome = $service->sync($this->company);

            if ($outcome !== SyncOutcome::MorePending) {
                SyncProgress::done($this->company->id, $outcome);
            }
        } catch (\Throwable $e) {
            SyncProgress::done($this->company->id, SyncOutcome::Failed, $e->getMessage());
            throw $e;
        }
    }
}
