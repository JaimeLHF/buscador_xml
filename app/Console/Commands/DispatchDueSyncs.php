<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanyDfe;
use App\Models\SyncState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('sefaz:dispatch-due')]
#[Description('Enfileira a sincronização DF-e das empresas ativas, com certificado válido e consulta liberada')]
class DispatchDueSyncs extends Command
{
    public function handle(): int
    {
        $count = 0;

        // Uma fila por empresa e tipo (NF-e e CT-e são serviços diferentes na SEFAZ, com limites próprios).
        SyncState::query()
            ->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->whereHas('company', fn (Builder $q) => $q
                ->where('ativo', true)
                ->when(! config('sefaz.allow_production'), fn (Builder $q) => $q->where('ambiente', 2))
                ->whereHas('certificates', fn (Builder $q) => $q->where('active', true)->where('valid_to', '>', now())))
            ->with('company')
            ->each(function (SyncState $state) use (&$count): void {
                SyncCompanyDfe::dispatch($state->company, $state->tipo);
                $count++;
            });

        $this->components->info("{$count} sincronização(ões) enfileirada(s).");

        return self::SUCCESS;
    }
}
