<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\StoreOfficeCertificate;
use App\Enums\AuditAcao;
use App\Services\Certificates\Exceptions\InvalidCertificateException;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Upload do certificado do escritório contábil; fora do Livewire, pelo mesmo motivo do certificado da empresa. */
class OfficeCertificateController extends Controller
{
    public function store(Request $request, StoreOfficeCertificate $action): RedirectResponse
    {
        abort_unless($request->user()->managesCurrentOrganization(), 403);

        $request->validate([
            'pfx' => ['required', 'file', 'max:64', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:255'],
        ], attributes: ['pfx' => 'certificado', 'password' => 'senha do certificado']);

        $organization = $request->user()->currentOrganization;

        try {
            $certificate = $action->handle(
                $organization,
                (string) file_get_contents($request->file('pfx')->getRealPath()),
                $request->string('password')->toString(),
            );
        } catch (InvalidCertificateException $e) {
            throw ValidationException::withMessages(['pfx' => $e->getMessage()]);
        }

        Audit::record(AuditAcao::CertificadoEnviado, $organization->id, descricao: __('Escritório: :t', ['t' => $certificate->titular]));

        return redirect()->route('companies.index')->with('success', __('Certificado do escritório salvo. As empresas de SC no modo "certificado do contador" já podem buscar notas.'));
    }
}
