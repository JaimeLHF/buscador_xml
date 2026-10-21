<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Models\Company;
use App\Services\Certificates\CertificateVault;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestCertificate;

it('reads certificates encrypted with a previous key and re-encrypts them with the new one', function () {
    $company = Company::factory()->create(['cnpj' => '12345678000195']);
    app(StoreCompanyCertificate::class)->handle($company, TestCertificate::pfx(), '1234');
    $oldKey = config('certificates.key');
    $before = DB::table('certificates')->value('password');

    config(['certificates.key' => 'base64:'.base64_encode(random_bytes(32)), 'certificates.previous_keys' => [$oldKey]]);
    app()->forgetInstance(CertificateVault::class);

    expect($company->activeCertificate()->first()->password)->toBe('1234');

    $this->artisan('certificates:reencrypt')->assertSuccessful();

    config(['certificates.previous_keys' => []]);
    app()->forgetInstance(CertificateVault::class);

    expect(DB::table('certificates')->value('password'))->not->toBe($before)
        ->and($company->activeCertificate()->first()->password)->toBe('1234');
});
