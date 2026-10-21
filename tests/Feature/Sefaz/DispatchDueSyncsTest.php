<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Enums\Ambiente;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
use App\Services\Sefaz\Exceptions\SefazException;
use App\Services\Sefaz\SpedNfeGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestCertificate;

function companyWithCertificate(array $attributes = []): Company
{
    $company = Company::factory()->create(['cnpj' => '12345678000195', ...$attributes]);
    app(StoreCompanyCertificate::class)->handle($company, TestCertificate::pfx(), '1234');

    return $company;
}

it('dispatches only active companies with a valid certificate whose check is due', function () {
    Queue::fake();

    $due = companyWithCertificate();
    $waiting = companyWithCertificate();
    $waiting->syncState()->update(['next_check_at' => now()->addMinutes(30)]);
    $inactive = companyWithCertificate(['ativo' => false]);
    $production = companyWithCertificate(['ambiente' => Ambiente::Producao]);
    $withoutCertificate = Company::factory()->create();

    $this->artisan('sefaz:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SyncCompanyDfe::class, 1);
    Queue::assertPushed(SyncCompanyDfe::class, fn (SyncCompanyDfe $job) => $job->company->is($due) && $job->queue === 'sefaz');
});

it('skips companies whose certificate expired', function () {
    Queue::fake();
    companyWithCertificate();
    $this->travel(400)->days();

    $this->artisan('sefaz:dispatch-due');

    Queue::assertNothingPushed();
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'sefaz:dispatch-due'));

    expect($event?->expression)->toBe('* * * * *');
});

it('refuses to call production unless explicitly allowed', function () {
    $company = companyWithCertificate(['ambiente' => Ambiente::Producao]);

    expect(fn () => (new SpedNfeGateway)->distNsu($company, '0'))->toThrow(SefazException::class, 'produção');
});
