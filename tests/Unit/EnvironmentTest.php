<?php

use Symfony\Component\Process\Process;

it('has the PHP extensions required by sped-nfe and sped-da', function (string $extension) {
    expect(extension_loaded($extension))->toBeTrue();
})->with(['openssl', 'soap', 'curl', 'dom', 'xml', 'zlib', 'mbstring', 'zip', 'gd']);

it('has an openssl binary able to read legacy pfx files', function () {
    $process = new Process([config('certificates.openssl_binary', 'openssl'), 'version']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toStartWith('OpenSSL 3');
});

it('never allows production SEFAZ calls in tests, whatever .env says', function () {
    expect(config('sefaz.allow_production'))->toBeFalse();
});
