<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        return $this->user()->can('manageCertificate', $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A1 típico tem 3–10 KB; o mime de .pfx varia entre sistemas, por isso valida-se a extensão.
            'pfx' => ['required', 'file', 'max:64', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pfx' => 'certificado',
            'password' => 'senha do certificado',
        ];
    }
}
