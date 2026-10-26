<?php

namespace App\Http\Controllers;

use App\Enums\DocumentoTipo;
use App\Jobs\SyncCompanyDfe;
use App\Jobs\SyncCompanySefSc;
use App\Models\Company;
use App\Services\Sefaz\CnpjCooldown;
use App\Services\Sefaz\SyncProgress;
use App\Services\SefSc\SefScDistribuicaoService;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** "Sincronizar agora" nunca fura a espera da NT: se ainda não é hora, informa quando poderá rodar. */
class SyncNowController extends Controller
{
    public function __invoke(Company $company, Request $request, CnpjCooldown $cooldown): RedirectResponse
    {
        Gate::authorize('view', $company);

        if ($company->buscaPeloContador()) {
            return $this->sefSc($company);
        }

        if (! $company->activeCertificate || $company->activeCertificate->isExpired()) {
            return back()->with('error', __('Envie um certificado válido antes de sincronizar.'));
        }

        $filtroTipo = $request->input('tipo');
        $tipos = match ($filtroTipo) {
            'nfe' => [DocumentoTipo::Nfe],
            'cte' => [DocumentoTipo::Cte],
            default => DocumentoTipo::cases(),
        };
        $tipos = array_values(array_filter($tipos, $company->buscaTipo(...)));

        if ($tipos === []) {
            return back()->with('error', __('A busca de CT-e está desligada para esta empresa. Ligue em "Editar empresa".'));
        }

        $enfileiradas = 0;

        foreach ($tipos as $tipo) {
            $state = $company->syncState($tipo);
            $until = collect([$state->next_check_at, $cooldown->until($company, $tipo)])
                ->filter(fn (?CarbonImmutable $d): bool => (bool) $d?->isFuture())->max();

            if (! $until) {
                SyncCompanyDfe::dispatch($company, $tipo);
                $enfileiradas++;
            }
        }

        if ($enfileiradas === 0) {
            $state = $company->syncState($tipos[0]);
            $until = collect([$state->next_check_at, $cooldown->until($company, $tipos[0])])
                ->filter(fn (?CarbonImmutable $d): bool => (bool) $d?->isFuture())->max();

            return back()->with('error', __('A SEFAZ só permite nova consulta deste CNPJ a partir de :hora.', ['hora' => Fmt::dateTime($until)]));
        }

        $label = match ($filtroTipo) {
            'nfe' => 'NF-e',
            'cte' => 'CT-e',
            default => $company->buscar_cte ? 'NF-e/CT-e' : 'NF-e',
        };

        SyncProgress::queued($company->id, __("Sincronização {$label} enfileirada…"));

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

        SyncProgress::queued($company->id, __('Busca na SEF-SC enfileirada…'));

        return back()->with('success', __('Busca na SEF-SC enfileirada. Os documentos aparecem em instantes.'));
    }
}
