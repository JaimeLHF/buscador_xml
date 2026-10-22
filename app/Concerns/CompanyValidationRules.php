<?php

namespace App\Concerns;

use App\Enums\Ambiente;
use App\Enums\ModoBusca;
use App\Enums\Uf;
use App\Models\Company;
use App\Rules\ValidCnpj;
use App\Support\Cnpj;
use Illuminate\Validation\Rule;

trait CompanyValidationRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function companyRules(int $organizationId, ?int $ignoreId = null): array
    {
        return [
            'cnpj' => [
                'required', 'string', 'max:18', new ValidCnpj,
                function (string $attribute, mixed $value, \Closure $fail) use ($organizationId, $ignoreId): void {
                    $exists = Company::query()
                        ->where('organization_id', $organizationId)
                        ->where('cnpj', Cnpj::normalize((string) $value))
                        ->where('ambiente', (int) $this->ambiente)
                        ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                        ->exists();

                    if ($exists) {
                        $fail('Já existe uma empresa com este CNPJ neste ambiente.');
                    }
                },
            ],
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'uf' => ['required', Rule::enum(Uf::class)],
            'ambiente' => ['required', Rule::enum(Ambiente::class)],
            'ativo' => ['boolean'],
            'manifestacao_automatica' => ['boolean'],
            'modo_busca' => ['required', Rule::enum(ModoBusca::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function companyAttributes(): array
    {
        return [
            'cnpj' => 'CNPJ',
            'razao_social' => 'razão social',
            'nome_fantasia' => 'nome fantasia',
            'uf' => 'UF',
            'ambiente' => 'ambiente',
            'modo_busca' => 'forma de busca',
        ];
    }
}
