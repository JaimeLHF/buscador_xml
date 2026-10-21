<?php

namespace App\Console\Commands;

use App\Enums\DocumentoTipo;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
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

        Company::query()
            ->where('ativo', true)
            ->when(! config('sefaz.allow_production'), fn (Builder $q) => $q->where('ambiente', 2))
            ->whereHas('certificates', fn (Builder $q) => $q->where('active', true)->where('valid_to', '>', now()))
            ->whereHas('syncStates', fn (Builder $q) => $q
                ->where('tipo', DocumentoTipo::Nfe)
                ->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now())))
            ->each(function (Company $company) use (&$count): void {
                SyncCompanyDfe::dispatch($company);
                $count++;
            });

        $this->components->info("{$count} empresa(s) enfileirada(s).");

        return self::SUCCESS;
    }
}
