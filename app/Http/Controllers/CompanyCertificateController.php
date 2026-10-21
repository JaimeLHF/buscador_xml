<?php

namespace App\Http\Controllers;

use App\Actions\Companies\StoreCompanyCertificate;
use App\Http\Requests\StoreCertificateRequest;
use App\Models\Company;
use App\Services\Certificates\Exceptions\InvalidCertificateException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Upload fora do Livewire de propósito: o arquivo fica só no tmp do PHP durante a requisição,
 * sem passar pelo diretório de uploads temporários do Livewire.
 */
class CompanyCertificateController extends Controller
{
    public function store(StoreCertificateRequest $request, Company $company, StoreCompanyCertificate $action): RedirectResponse
    {
        try {
            $action->handle(
                $company,
                (string) file_get_contents($request->file('pfx')->getRealPath()),
                $request->string('password')->toString(),
            );
        } catch (InvalidCertificateException $e) {
            throw ValidationException::withMessages(['pfx' => $e->getMessage()]);
        }

        return redirect()->route('companies.show', $company)->with('status', 'certificate-saved');
    }
}
