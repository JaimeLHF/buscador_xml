<?php

namespace App\Http\Controllers;

use App\Enums\DocumentoTipo;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
use App\Services\Sefaz\CnpjCooldown;
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

        $state = $company->syncState(DocumentoTipo::Nfe);
        $until = collect([$state->next_check_at, $cooldown->until($company)])->filter(fn (?CarbonImmutable $d): bool => (bool) $d?->isFuture())->max();

        if ($until) {
            return back()->with('error', __('A SEFAZ só permite nova consulta deste CNPJ a partir de :hora.', ['hora' => Fmt::dateTime($until)]));
        }

        if (! $company->activeCertificate || $company->activeCertificate->isExpired()) {
            return back()->with('error', __('Envie um certificado válido antes de sincronizar.'));
        }

        SyncCompanyDfe::dispatch($company);

        return back()->with('success', __('Sincronização enfileirada. Os documentos aparecem em instantes.'));
    }
}
