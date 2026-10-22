<?php

use App\Services\Documents\NfeReader;
use Tests\Support\SefazFixtures as F;

it('reads every section of a full NF-e with translated codes', function () {
    $nfe = (new NfeReader(F::file('procNFe.xml')))->read();

    expect($nfe['tipo'])->toBe('completo')
        ->and($nfe['ide'])->toMatchArray(['Número' => '123', 'Série' => '1', 'Natureza da operação' => 'VENDA DE MERCADORIA', 'Tipo' => 'Saída', 'Finalidade' => 'Normal', 'Ambiente' => 'Homologação'])
        ->and($nfe['emitente'])->toMatchArray(['Nome' => 'FORNECEDOR EXEMPLO LTDA', 'CNPJ' => '11222333000181', 'Regime tributário' => 'Regime normal'])
        ->and($nfe['emitente']['Endereço'])->toContain('RUA A 100')->toContain('FLORIANOPOLIS/SC')->toContain('CEP 88000-000')
        ->and($nfe['destinatario']['CNPJ'])->toBe('12345678000195')
        ->and($nfe['itens'])->toHaveCount(1)
        ->and($nfe['itens'][0])->toMatchArray(['descricao' => 'PRODUTO TESTE', 'ncm' => '84719012', 'cfop' => '5102', 'ean' => null])
        ->and($nfe['totais'])->toHaveKey('Total da nota')
        ->and($nfe['xml'])->toContain('<infNFe');
});

it('reads the summary of a resNFe', function () {
    $nfe = (new NfeReader(F::file('resNFe.xml')))->read();

    expect($nfe['tipo'])->toBe('resumo')
        ->and($nfe['resumo'])->toHaveKeys(['Emitente', 'CNPJ', 'Valor total']);
});

it('rejects anything that is not an NF-e', function () {
    (new NfeReader('<foo/>'))->read();
})->throws(RuntimeException::class);

it('reads a CT-e with parties, freight values, cargo and transported NF-e', function () {
    $cte = (new NfeReader(F::file('procCTe.xml')))->read();

    expect($cte['tipo'])->toBe('cte')
        ->and($cte['ide'])->toMatchArray(['Número' => '456', 'Modal' => 'Rodoviário', 'Tipo de serviço' => 'Normal', 'Origem' => 'FLORIANOPOLIS/SC', 'Destino' => 'SAO PAULO/SP', 'Tomador do serviço' => 'Destinatário'])
        ->and(array_keys($cte['partes']))->toBe(['Emitente (transportadora)', 'Remetente', 'Destinatário'])
        ->and($cte['valores']['total'])->toBe('350.00')
        ->and($cte['valores']['componentes'])->toHaveCount(2)
        ->and($cte['icms']['Valor do ICMS'])->toBe('42.00')
        ->and($cte['carga']['dados']['Produto predominante'])->toBe('MOVEIS')
        ->and($cte['nfes'])->toBe(['42261011222333000181550010000001231123456788'])
        ->and($cte['modal']['RNTRC'])->toBe('12345678')
        ->and($cte['protocolo']['Protocolo'])->toBe('342260000012345');
});
