<?php

use App\Support\Cnpj;

it('validates numeric CNPJs', function () {
    expect(Cnpj::isValid('11.222.333/0001-81'))->toBeTrue()
        ->and(Cnpj::isValid('11222333000182'))->toBeFalse()
        ->and(Cnpj::isValid('00000000000000'))->toBeFalse();
});

it('validates alphanumeric CNPJs from the RFB example', function () {
    expect(Cnpj::isValid('12.ABC.345/01DE-35'))->toBeTrue()
        ->and(Cnpj::isValid('12abc34501de35'))->toBeTrue()
        ->and(Cnpj::isValid('12ABC34501DE36'))->toBeFalse()
        ->and(Cnpj::isValid('12ABC34501DEAB'))->toBeFalse();
});

it('normalizes, formats and extracts the root', function () {
    $cnpj = Cnpj::from('12.abc.345/01de-35');

    expect($cnpj->value)->toBe('12ABC34501DE35')
        ->and($cnpj->formatted())->toBe('12.ABC.345/01DE-35')
        ->and($cnpj->root())->toBe('12ABC345');
});

it('builds a CNPJ from a 12 character base', function () {
    expect(Cnpj::fromBase('112223330001')->value)->toBe('11222333000181')
        ->and(Cnpj::fromBase('12ABC34501DE')->value)->toBe('12ABC34501DE35');
});
