<?php

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\User;
use App\Services\MeuDanfe\SpedChaves;

beforeEach(function () {
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195']);
    [, $this->plain] = ApiToken::issue($this->user->currentOrganization, 'Costhub', $this->user);
});

function cobertura(string $plain, string $cnpj = '12345678000195', string $de = '2026-09', string $ate = '2026-10'): array
{
    return test()->withToken($plain)->getJson("/api/v1/cobertura?empresa_cnpj={$cnpj}&de={$de}&ate={$ate}")->assertOk()->json();
}

/** Cenário de outubro e setembro de 2026, com uma chave em cada situação. */
function cenarioCobertura(Company $company): void
{
    // Recebida pela SEFAZ e escriturada: o importador do SPED registra a chave como encontrada.
    notaApi($company, chaveApi(1));
    SpedChaves::importar($company, implode("\r\n", [
        '|C100|0|1|F1|55|00|1|1|'.chaveApi(1).'|20102026|20102026|100,00|',
        '|C100|0|1|F1|55|00|1|2|'.chaveApi(2).'|05102026|05102026|100,00|',
    ]));

    // Sem nota e sem data do SPED: o mês sai da própria chave.
    $company->chavesHistoricas()->create(['chave' => chaveApi(3), 'status' => 'nao_encontrada']);
    $company->chavesHistoricas()->create(['chave' => chaveApi(4, '2609'), 'status' => 'erro', 'sped_data' => '2026-09-30']);

    // Remontada às 22h de 30/09 em Brasília (01h de 01/10 em UTC): conta em setembro.
    $company->chavesHistoricas()->create(['chave' => chaveApi(5, '2609'), 'status' => 'encontrada', 'sped_data' => '2026-09-30']);
    notaApi($company, chaveApi(5, '2609'), ['xml_reconstruido' => true, 'conferencia_sped' => 'confere', 'data_emissao' => '2026-10-01 01:00:00']);

    $company->chavesHistoricas()->create(['chave' => chaveApi(6), 'status' => 'encontrada', 'sped_data' => '2026-10-10']);
    notaApi($company, chaveApi(6), ['xml_reconstruido' => true, 'conferencia_sped' => 'diverge']);

    notaApi($company, chaveApi(7));                                                       // recebida, fora do SPED
    notaApi($company, chaveApi(8), ['emitente_cnpj' => $company->cnpj]);                  // saída: não conta
    notaApi($company, chaveApi(9), ['tem_xml_completo' => false]);                        // só resumo: não conta
    notaApi($company, chaveApi(10, '2611'), ['data_emissao' => '2026-11-02 12:00:00']);  // fora do intervalo
}

it('reports SPED keys, incoming notes, checks and notes outside the SPED per month', function () {
    cenarioCobertura($this->company);

    $r = cobertura($this->plain);

    expect($r['empresa_cnpj'])->toBe('12345678000195')
        ->and($r['data'])->toBe([
            [
                'mes' => '2026-09',
                'chaves_sped' => ['total' => 2, 'importada' => 0, 'pendente' => 0, 'encontrada' => 1, 'nao_encontrada' => 0, 'erro' => 1, 'mes_pela_chave' => 0],
                'notas_entrada' => ['total' => 1, 'sefaz' => 0, 'reconstruidas' => 1],
                'conferencia' => ['confere' => 1, 'diverge' => 0, 'sem_conferencia' => 0],
                'fora_do_sped' => 0,
            ],
            [
                'mes' => '2026-10',
                'chaves_sped' => ['total' => 4, 'importada' => 1, 'pendente' => 0, 'encontrada' => 2, 'nao_encontrada' => 1, 'erro' => 0, 'mes_pela_chave' => 1],
                'notas_entrada' => ['total' => 3, 'sefaz' => 2, 'reconstruidas' => 1],
                'conferencia' => ['confere' => 0, 'diverge' => 1, 'sem_conferencia' => 0],
                'fora_do_sped' => 1,
            ],
        ]);

    // Cada chave em exatamente um status: os baldes fecham com o total, mês a mês e no SPED inteiro.
    foreach ($r['data'] as $mes) {
        $c = $mes['chaves_sped'];
        expect($c['importada'] + $c['pendente'] + $c['encontrada'] + $c['nao_encontrada'] + $c['erro'])->toBe($c['total'])
            ->and($mes['notas_entrada']['sefaz'] + $mes['notas_entrada']['reconstruidas'])->toBe($mes['notas_entrada']['total'])
            ->and(array_sum($mes['conferencia']))->toBe($mes['notas_entrada']['reconstruidas']);
    }

    $amplo = cobertura($this->plain, de: '2026-01', ate: '2026-12');
    expect(array_sum(array_column(array_column($amplo['data'], 'chaves_sped'), 'total')))->toBe($this->company->chavesHistoricas()->count());
});

it('counts only the token organization, and nothing for a company of another one', function () {
    cenarioCobertura($this->company);
    cenarioCobertura(Company::factory()->create(['cnpj' => '12345678000195']));
    cenarioCobertura(Company::factory()->create(['cnpj' => CNPJ_OUTRA]));

    expect(cobertura($this->plain)['data'][1]['chaves_sped']['total'])->toBe(4)
        ->and(cobertura($this->plain)['data'][1]['notas_entrada']['total'])->toBe(3)
        ->and(cobertura($this->plain, CNPJ_OUTRA)['data'])->toBe([]);
});

it('validates the company and the month range', function (string $query) {
    $this->withToken($this->plain)->getJson('/api/v1/cobertura?'.$query)->assertUnprocessable();
})->with([
    'sem empresa' => ['de=2026-09&ate=2026-10'],
    'cnpj inválido' => ['empresa_cnpj=12345678000190&de=2026-09&ate=2026-10'],
    'mês invertido' => ['empresa_cnpj=12345678000195&de=2026-10&ate=2026-09'],
    'intervalo longo' => ['empresa_cnpj=12345678000195&de=2020-01&ate=2026-10'],
]);
