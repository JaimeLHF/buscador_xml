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
