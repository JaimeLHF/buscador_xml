<?php

namespace Database\Factories;

use App\Enums\Ambiente;
use App\Enums\Uf;
use App\Models\Company;
use App\Models\Organization;
use App\Support\Cnpj;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'cnpj' => Cnpj::fromBase(fake()->numerify('########0001'))->value,
            'razao_social' => fake()->company().' LTDA',
            'nome_fantasia' => fake()->optional()->company(),
            'uf' => fake()->randomElement(Uf::cases()),
            'ambiente' => Ambiente::Homologacao,
            'ativo' => true,
            'manifestacao_automatica' => false,
        ];
    }
}
