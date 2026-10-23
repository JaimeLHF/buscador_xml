<?php

namespace App\Console\Commands;

use App\Enums\Ambiente;
use App\Enums\ModoBusca;
use App\Enums\Uf;
use App\Jobs\SyncCompanyDfe;
use App\Jobs\SyncCompanySefSc;
use App\Models\Company;
use App\Models\SyncState;
use App\Services\Sefaz\SyncProgress;
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
                ->where('modo_busca', ModoBusca::CertificadoEmpresa)
                ->when(! config('sefaz.allow_production'), fn (Builder $q) => $q->where('ambiente', 2))
                ->whereHas('certificates', fn (Builder $q) => $q->where('active', true)->where('valid_to', '>', now())))
            ->with('company')
            ->each(function (SyncState $state) use (&$count): void {
                SyncProgress::queued($state->company_id, 'Sincronização automática');
                SyncCompanyDfe::dispatch($state->company, $state->tipo);
                $count++;
            });

        // Modo "certificado do contador": empresas de SC buscadas pela SEF-SC com o certificado do escritório.
        Company::query()
            ->where('ativo', true)
            ->where('modo_busca', ModoBusca::ContadorSc)
            ->where('uf', Uf::SC)
            ->where('ambiente', Ambiente::Producao)
            ->when(! config('sefaz.allow_production'), fn (Builder $q) => $q->whereRaw('false'))
            ->whereHas('organization.officeCertificates', fn (Builder $q) => $q->where('active', true)->where('valid_to', '>', now()))
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('sefScSyncState')
                ->orWhereHas('sefScSyncState', fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now())))
            ->each(function (Company $company) use (&$count): void {
                SyncProgress::queued($company->id, 'Sincronização automática SEF-SC');
                SyncCompanySefSc::dispatch($company);
                $count++;
            });

        $this->components->info("{$count} sincronização(ões) enfileirada(s).");

        return self::SUCCESS;
    }
}
