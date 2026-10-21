<?php

namespace App\Actions\Companies;

use App\Models\Certificate;
use App\Models\Company;
use App\Services\Certificates\CertificateInspector;
use App\Services\Certificates\Exceptions\InvalidCertificateException;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;

class StoreCompanyCertificate
{
    public function __construct(private CertificateInspector $inspector) {}

    /**
     * @throws InvalidCertificateException
     */
    public function handle(Company $company, string $pfx, string $password): Certificate
    {
        $data = $this->inspector->inspect($pfx, $password);

        if ($data->validTo->isPast()) {
            throw InvalidCertificateException::expired();
        }

        if ($data->validFrom->isFuture()) {
            throw InvalidCertificateException::notYetValid();
        }

        // Um e-CNPJ da matriz assina para as filiais: basta a raiz (8 primeiras posições) coincidir.
        if (Cnpj::from($data->cnpj)->root() !== $company->cnpjValue()->root()) {
            throw InvalidCertificateException::cnpjMismatch();
        }

        return DB::transaction(function () use ($company, $data, $password): Certificate {
            $company->certificates()->where('active', true)->update(['active' => false]);

            return $company->certificates()->create([
                'pfx' => $data->pfx,
                'password' => $password,
                'titular' => $data->titular,
                'cnpj_certificado' => $data->cnpj,
                'valid_from' => $data->validFrom,
                'valid_to' => $data->validTo,
                'fingerprint' => $data->fingerprint,
                'active' => true,
            ]);
        });
    }
}
