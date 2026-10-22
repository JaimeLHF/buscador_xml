<?php

namespace App\Actions\Organizations;

use App\Models\Certificate;
use App\Models\Organization;
use App\Services\Certificates\CertificateInspector;
use App\Services\Certificates\Exceptions\InvalidCertificateException;
use Illuminate\Support\Facades\DB;

/**
 * Guarda o certificado do escritório contábil, usado por todas as empresas da organização no modo
 * "certificado do contador (SC)". Aceita e-CNPJ do escritório ou e-CPF do contador; quem confere o
 * vínculo com cada empresa é a SEF-SC, na consulta (rejeição 8002).
 */
class StoreOfficeCertificate
{
    public function __construct(private CertificateInspector $inspector) {}

    /**
     * @throws InvalidCertificateException
     */
    public function handle(Organization $organization, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password): Certificate
    {
        $data = $this->inspector->inspect($pfx, $password, aceitaCpf: true);

        if ($data->validTo->isPast()) {
            throw InvalidCertificateException::expired();
        }

        if ($data->validFrom->isFuture()) {
            throw InvalidCertificateException::notYetValid();
        }

        return DB::transaction(function () use ($organization, $data, $password): Certificate {
            $organization->officeCertificates()->where('active', true)->update(['active' => false]);

            return $organization->officeCertificates()->create([
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
