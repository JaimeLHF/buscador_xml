<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Enums\DocumentoTipo;
use App\Enums\SefazServico;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
use App\Models\User;
use App\Services\Sefaz\CnpjCooldown;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestCertificate;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195']);
    app(StoreCompanyCertificate::class)->handle($this->company, TestCertificate::pfx(), '1234');
});

it('enqueues a sync when the CNPJ is allowed to query', function () {
    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('success');

    Queue::assertPushed(SyncCompanyDfe::class);
});

it('respects next_check_at and tells when it can run', function () {
    $this->company->syncStates()->update(['next_check_at' => now()->addMinutes(40)]);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company))
        ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'a partir de'));

    Queue::assertNothingPushed();
});

it('queues only the document types whose wait is over', function () {
    $this->company->syncState()->update(['next_check_at' => now()->addMinutes(40)]);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('success');

    Queue::assertPushed(SyncCompanyDfe::class, 1);
    Queue::assertPushed(SyncCompanyDfe::class, fn ($job) => $job->tipo === DocumentoTipo::Cte);
});

it('respects the shared CNPJ cooldown', function () {
    app(CnpjCooldown::class)->set($this->company, now()->addMinutes(30)->toImmutable());
    app(CnpjCooldown::class)->set($this->company, now()->addMinutes(30)->toImmutable(), DocumentoTipo::Cte);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('shows the SEFAZ log only for the current organization, without sensitive data', function () {
    $this->company->sefazRequests()->create(['servico' => SefazServico::DistNsu, 'tp_amb' => 2, 'cstat' => '137', 'xmotivo' => 'Nenhum documento localizado', 'ult_nsu_enviado' => '000000000000000']);
    $foreign = Company::factory()->create();
    $foreign->sefazRequests()->create(['servico' => SefazServico::DistNsu, 'tp_amb' => 2, 'cstat' => '656', 'xmotivo' => 'Consumo indevido de outro cliente']);

    $this->actingAs($this->user)->get(route('sefaz-log'))
        ->assertOk()
        ->assertSee('Nenhum documento localizado')
        ->assertDontSee('Consumo indevido de outro cliente');
});
