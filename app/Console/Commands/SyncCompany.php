<?php

namespace App\Console\Commands;

use App\Enums\DocumentoTipo;
use App\Models\Company;
use App\Services\Sefaz\DistribuicaoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sefaz:sync {company : ID da empresa} {--tipo=nfe : nfe ou cte}')]
#[Description('Executa a distribuição DF-e de uma empresa agora (respeita next_check_at e o lock por CNPJ)')]
class SyncCompany extends Command
{
    public function handle(DistribuicaoService $service): int
    {
        $company = Company::findOrFail((int) $this->argument('company'));
        $tipo = DocumentoTipo::from((string) $this->option('tipo'));
        $outcome = $service->sync($company, $tipo);

        $state = $company->syncState($tipo)->refresh();

        $this->components->twoColumnDetail('Resultado', $outcome->value);
        $this->components->twoColumnDetail('cStat', (string) $state->last_cstat.' '.$state->last_xmotivo);
        $this->components->twoColumnDetail('ultNSU / maxNSU', "{$state->ult_nsu} / {$state->max_nsu}");
        $this->components->twoColumnDetail('Próxima consulta', (string) $state->next_check_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'));
        $this->components->twoColumnDetail('Documentos', (string) $company->documents()->where('tipo', $tipo)->count());

        if ($state->last_error) {
            $this->components->warn($state->last_error);
        }

        return self::SUCCESS;
    }
}
