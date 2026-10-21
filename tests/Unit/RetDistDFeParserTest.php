<?php

use App\Enums\CStat;
use App\Enums\DfeSchema;
use App\Services\Sefaz\Exceptions\SefazException;
use App\Services\Sefaz\RetDistDFeParser;
use Tests\Support\SefazFixtures as F;

it('parses a 137 SOAP envelope', function () {
    $ret = (new RetDistDFeParser)->parse(F::file('ret_137.xml'));

    expect($ret->is(CStat::NenhumDocumento))->toBeTrue()
        ->and($ret->ultNsu)->toBe('000000000000010')
        ->and($ret->documents)->toBe([])
        ->and($ret->hasMore())->toBeFalse();
});

it('decodes base64 + gzip docZips and identifies schemas', function () {
    $ret = (new RetDistDFeParser)->parse(F::ret138('2', '9', [
        F::doc('1', 'resNFe.xml', 'resNFe_v1.01.xsd'),
        F::doc('2', 'procNFe.xml', 'procNFe_v4.00.xsd'),
    ]));

    expect($ret->ultNsu)->toBe('000000000000002')
        ->and($ret->hasMore())->toBeTrue()
        ->and($ret->documents[0]->nsu)->toBe('000000000000001')
        ->and($ret->documents[0]->schema)->toBe(DfeSchema::ResNFe)
        ->and($ret->documents[1]->schema)->toBe(DfeSchema::ProcNFe)
        ->and($ret->documents[1]->xml)->toBe(F::file('procNFe.xml'));
});

it('rejects responses that are not a retDistDFeInt', function (string $body) {
    (new RetDistDFeParser)->parse($body);
})->throws(SefazException::class)->with(['', '<html>erro</html>', 'não é xml']);
