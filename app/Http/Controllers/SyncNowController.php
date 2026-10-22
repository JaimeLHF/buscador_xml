<?php

namespace App\Http\Controllers;

use App\Enums\DocumentoTipo;
use App\Jobs\SyncCompanyDfe;
use App\Jobs\SyncCompanySefSc;
use App\Models\Company;
use App\Services\Sefaz\CnpjCooldown;
use App\Services\SefSc\SefScDistribuicaoService;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** "Sincronizar agora" nunca fura a espera da NT: se ainda não é hora, informa quando poderá rodar. */
class SyncNowController extends Controller
{
    public function __invoke(Company $company, CnpjCooldown $cooldown): RedirectResponse
    {
        Gate::authorize('view', $company);

        if ($company->buscaPeloContador()) {
            return $this->sefSc($company);
        }

        $state = $company->syncState(DocumentoTipo::Nfe);
        $until = collect([$state->next_check_at, $cooldown->until($company)])->filter(fn (?CarbonImmutable $d): bool => (bool) $d?->isFuture())->max();
        $cte = $company->syncState(DocumentoTipo::Cte);
        $cteLiberado = ! collect([$cte->next_check_at, $cooldown->until($company, DocumentoTipo::Cte)])->contains(fn (?CarbonImmutable $d): bool => (bool) $d?->isFuture());

        if ($until && ! $cteLiberado) {
            return back()->with('error', __('A SEFAZ só permite nova consulta deste CNPJ a partir de :hora.', ['hora' => Fmt::dateTime($until)]));
        }

        if (! $company->activeCertificate || $company->activeCertificate->isExpired()) {
            return back()->with('error', __('Envie um certificado válido antes de sincronizar.'));
        }

        // Cada tipo só é enfileirado se a SEFAZ já liberou a próxima consulta dele.
        if (! $until) {
            SyncCompanyDfe::dispatch($company);
        }

        if ($cteLiberado) {
            SyncCompanyDfe::dispatch($company, DocumentoTipo::Cte);
        }

        return back()->with('success', __('Sincronização enfileirada. Os documentos aparecem em instantes.'));
    }

    /** A SEF-SC bloqueia (657) quem consulta antes de 12 horas depois de receber tudo: o botão respeita a mesma espera. */
    private function sefSc(Company $company): RedirectResponse
    {
        if ($impedimento = SefScDistribuicaoService::impedimento($company)) {
            return back()->with('error', $impedimento);
        }

        $proxima = $company->sefScState()->next_check_at;

        if ($proxima?->isFuture()) {
            return back()->with('error', __('A SEF-SC só permite nova busca desta empresa a partir de :hora.', ['hora' => Fmt::dateTime($proxima)]));
        }

        SyncCompanySefSc::dispatch($company);

        return back()->with('success', __('Busca na SEF-SC enfileirada. Os documentos aparecem em instantes.'));
    }
}
