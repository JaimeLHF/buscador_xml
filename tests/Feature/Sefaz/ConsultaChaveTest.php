<?php

use App\Models\Company;
use App\Services\Sefaz\ConsultaChaveService;
use App\Services\Sefaz\ConsultaLimiter;
use App\Services\Sefaz\Exceptions\SefazException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SefazFixtures as F;

beforeEach(function () {
    Storage::fake('local');
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
});

it('fetches a full document by access key', function () {
    fakeSefaz()->push(F::ret138('000000000000000', '000000000000000', [F::doc('000000000000000', 'procNFe.xml', 'procNFe_v4.00.xsd')]));

    app(ConsultaChaveService::class)->consultar($this->company, '42261011222333000181550010000001231123456788');

    expect($this->company->documents()->sole()->tem_xml_completo)->toBeTrue()
        ->and(fakeSefaz()->calls[0]['method'])->toBe('consChave');
});

it('never exceeds 20 key/NSU queries per hour for the same CNPJ', function () {
    $limiter = app(ConsultaLimiter::class);

    foreach (range(1, 20) as $_) {
        expect($limiter->attempt($this->company))->toBeTrue();
    }

    expect($limiter->attempt($this->company))->toBeFalse()
        ->and($limiter->remaining($this->company))->toBe(0);

    fakeSefaz()->push(F::file('ret_137.xml'));
    expect(fn () => app(ConsultaChaveService::class)->consultar($this->company, '42261011222333000181550010000001231123456788'))
        ->toThrow(SefazException::class);

    expect(fakeSefaz()->calls)->toBe([]);
});

it('counts the limit per CNPJ, shared across registrations of the same CNPJ', function () {
    $twin = Company::factory()->create(['cnpj' => '12345678000195']);
    $other = Company::factory()->create(['cnpj' => '11222333000181']);
    $limiter = app(ConsultaLimiter::class);

    foreach (range(1, 20) as $_) {
        $limiter->attempt($this->company);
    }

    expect($limiter->attempt($twin))->toBeFalse()
        ->and($limiter->attempt($other))->toBeTrue();
});
