<?php

use App\Models\Certificate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestCertificate;

beforeEach(function () {
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195']);
});

function uploadPfx(string $pfx, string $password = '1234'): array
{
    return [
        'pfx' => UploadedFile::fake()->createWithContent('cert.pfx', $pfx),
        'password' => $password,
    ];
}

it('stores a valid certificate encrypted at rest', function () {
    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx()))
        ->assertRedirect(route('companies.show', $this->company))
        ->assertSessionHasNoErrors();

    $certificate = $this->company->activeCertificate;

    expect($certificate->cnpj_certificado)->toBe('12345678000195')
        ->and($certificate->titular)->toBe('EMPRESA TESTE LTDA')
        ->and($certificate->fingerprint)->toHaveLength(64)
        ->and($certificate->password)->toBe('1234')
        ->and(openssl_pkcs12_read($certificate->pfx, $parts, '1234'))->toBeTrue();

    $raw = DB::table('certificates')->first();
    expect($raw->password)->not->toContain('1234')
        ->and($raw->pfx)->not->toBe($certificate->pfx);
});

it('converts legacy RC2/3DES pfx files into a modern PKCS#12', function () {
    $legacy = TestCertificate::pfx(legacy: true);
    expect(@openssl_pkcs12_read($legacy, $parts, '1234'))->toBeFalse();

    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx($legacy))
        ->assertSessionHasNoErrors();

    expect(openssl_pkcs12_read($this->company->activeCertificate->pfx, $parts, '1234'))->toBeTrue();
});

it('rejects a wrong password', function (bool $legacy) {
    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx(legacy: $legacy), 'errada'))
        ->assertSessionHasErrors(['pfx' => 'Senha do certificado incorreta.']);

    expect(Certificate::count())->toBe(0);
})->with(['modern' => false, 'legacy' => true]);

it('rejects an expired certificate', function () {
    $pfx = TestCertificate::pfx(validDays: 1);
    $this->travel(2)->days();

    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx($pfx))
        ->assertSessionHasErrors(['pfx' => 'O certificado está vencido.']);
});

it('rejects a certificate from another CNPJ root but accepts a branch of the same root', function () {
    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx(cnpj: '11222333000181')))
        ->assertSessionHasErrors(['pfx' => 'A raiz do CNPJ do certificado não corresponde à da empresa.']);

    $branch = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000276']);

    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $branch), uploadPfx(TestCertificate::pfx()))
        ->assertSessionHasNoErrors();
});

it('rejects certificates without CNPJ and non-pfx files', function () {
    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx(withCnpj: false)))
        ->assertSessionHasErrors(['pfx' => 'O certificado não é um e-CNPJ (não contém CNPJ do titular).']);

    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), [
            'pfx' => UploadedFile::fake()->createWithContent('cert.pfx', 'lixo'),
            'password' => '1234',
        ])
        ->assertSessionHasErrors('pfx');
});

it('replaces the active certificate keeping history', function () {
    foreach ([1, 2] as $_) {
        $this->actingAs($this->user)
            ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx()));
    }

    expect($this->company->certificates()->count())->toBe(2)
        ->and($this->company->certificates()->where('active', true)->count())->toBe(1);
});

it('forbids uploading to a company of another organization', function () {
    $other = Company::factory()->create();

    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $other), uploadPfx(TestCertificate::pfx()))
        ->assertForbidden();
});

it('never exposes certificate secrets when serialized', function () {
    $this->actingAs($this->user)
        ->post(route('companies.certificates.store', $this->company), uploadPfx(TestCertificate::pfx()));

    expect($this->company->activeCertificate->toArray())->not->toHaveKeys(['pfx', 'password']);
});
