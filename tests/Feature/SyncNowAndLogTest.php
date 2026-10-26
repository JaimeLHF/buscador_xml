<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Enums\DocumentoTipo;
use App\Enums\SefazServico;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
use App\Models\User;
use App\Services\Sefaz\CnpjCooldown;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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
    $this->company->update(['buscar_cte' => true]);
    $this->company->syncState()->update(['next_check_at' => now()->addMinutes(40)]);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('success');

    Queue::assertPushed(SyncCompanyDfe::class, 1);
    Queue::assertPushed(SyncCompanyDfe::class, fn ($job) => $job->tipo === DocumentoTipo::Cte);
});

it('does not queue CT-e for a company with CT-e turned off', function () {
    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('success');

    Queue::assertPushed(SyncCompanyDfe::class, 1);
    Queue::assertPushed(SyncCompanyDfe::class, fn ($job) => $job->tipo === DocumentoTipo::Nfe);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company), ['tipo' => 'cte'])->assertSessionHas('error');
});

it('respects the shared CNPJ cooldown', function () {
    app(CnpjCooldown::class)->set($this->company, now()->addMinutes(30)->toImmutable());
    app(CnpjCooldown::class)->set($this->company, now()->addMinutes(30)->toImmutable(), DocumentoTipo::Cte);

    $this->actingAs($this->user)->post(route('companies.sync', $this->company))->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('rewinds for a period without skipping the SEFAZ wait', function () {
    $state = $this->company->syncState(DocumentoTipo::Nfe);
    $state->update(['ult_nsu' => '000000000394677', 'max_nsu' => '000000000394677', 'next_check_at' => now()->addMinutes(40)]);
    app(CnpjCooldown::class)->set($this->company, now()->addMinutes(40)->toImmutable());

    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->set('syncTipo', 'nfe')
        ->set('syncPeriodo', '3m')
        ->call('sincronizarTodas');

    Queue::assertNotPushed(SyncCompanyDfe::class);
    expect($state->refresh()->ult_nsu)->toBe('000000000079677')
        ->and($state->next_check_at->isFuture())->toBeTrue()
        ->and(app(CnpjCooldown::class)->until($this->company))->not->toBeNull();
});

it('does not rewind again when the period was already downloaded', function () {
    $state = $this->company->syncState(DocumentoTipo::Nfe);
    $state->update(['ult_nsu' => '000000000394677', 'max_nsu' => '000000000394677']);
    $this->company->documents()->create(['tipo' => DocumentoTipo::Nfe, 'chave' => str_repeat('1', 44), 'nsu' => '000000000079700', 'schema' => 'resNFe', 'situacao' => 'autorizada']);

    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->set('syncTipo', 'nfe')
        ->set('syncPeriodo', '3m')
        ->call('sincronizarTodas');

    expect($state->refresh()->ult_nsu)->toBe('000000000394677');
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
