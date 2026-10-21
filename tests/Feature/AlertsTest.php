<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Enums\OrganizationRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CertificateExpiring;
use App\Notifications\CompanyBlocked;
use App\Services\Sefaz\DistribuicaoService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;
use Tests\Support\TestCertificate;

beforeEach(function () {
    $this->owner = User::factory()->withOrganization()->create();
    $this->member = User::factory()->create();
    $this->owner->currentOrganization->addMember($this->member, OrganizationRole::Member);
    $this->company = Company::factory()->for($this->owner->currentOrganization)->create(['cnpj' => '12345678000195']);
});

it('alerts managers 30, 15 and 7 days before the certificate expires, once per milestone', function () {
    Notification::fake();
    app(StoreCompanyCertificate::class)->handle($this->company, TestCertificate::pfx(validDays: 40), '1234');

    $this->travelTo($this->company->activeCertificate->valid_to->subDays(30)->subHour());
    $this->artisan('alerts:certificates');
    $this->artisan('alerts:certificates');

    Notification::assertSentToTimes($this->owner, CertificateExpiring::class, 1);
    Notification::assertNotSentTo($this->member, CertificateExpiring::class);

    $this->travel(1)->days();
    $this->artisan('alerts:certificates');
    Notification::assertSentToTimes($this->owner, CertificateExpiring::class, 1);

    $this->travelTo($this->company->activeCertificate->valid_to->subDays(7)->subHour());
    $this->artisan('alerts:certificates');
    Notification::assertSentToTimes($this->owner, CertificateExpiring::class, 2);
});

it('alerts when consumo indevido repeats', function () {
    Notification::fake();
    $service = app(DistribuicaoService::class);

    foreach (range(1, 3) as $_) {
        fakeSefaz()->push(F::file('ret_656.xml'));
        $service->sync($this->company);
        $this->travel(66)->minutes();
    }

    Notification::assertSentToTimes($this->owner, CompanyBlocked::class, 1);
});

it('shows unread alerts of the current organization on the dashboard', function () {
    $this->owner->notify(new CompanyBlocked($this->company, 3));
    $other = User::factory()->withOrganization()->create();

    Livewire::actingAs($this->owner)->test('pages::dashboard')
        ->assertSee('bloqueios seguidos')
        ->call('dismiss', $this->owner->unreadNotifications()->first()->id)
        ->assertDontSee('bloqueios seguidos');
});
