<?php

use App\Enums\DfeSchema;
use App\Enums\SefazServico;
use App\Enums\Situacao;
use App\Models\Company;
use App\Models\SyncState;
use App\Services\Sefaz\DistribuicaoService;
use App\Services\Sefaz\SyncOutcome;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Exception\ValidatorException;
use Tests\Support\SefazFixtures as F;

beforeEach(function () {
    Storage::fake('local');
    $this->freezeSecond();
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
    $this->service = app(DistribuicaoService::class);
});

function state(): SyncState
{
    return test()->company->syncState()->refresh();
}

it('waits 65 minutes after cStat 137 and persists ultNSU', function () {
    fakeSefaz()->push(F::file('ret_137.xml'));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate);

    expect(state())
        ->ult_nsu->toBe('000000000000010')
        ->last_cstat->toBe('137')
        ->next_check_at->toEqual(now()->addMinutes(65));

    expect(fakeSefaz()->calls)->toBe([['method' => 'distNsu', 'arg' => '000000000000000']]);
});

it('never queries again before next_check_at', function () {
    fakeSefaz()->push(F::file('ret_137.xml'));
    $this->service->sync($this->company);

    $this->travel(64)->minutes();
    expect($this->service->sync($this->company))->toBe(SyncOutcome::NotDue);

    $this->travel(2)->minutes();
    fakeSefaz()->push(F::file('ret_137.xml'));
    expect($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate);

    expect(fakeSefaz()->calls)->toHaveCount(2)
        ->and(fakeSefaz()->calls[1]['arg'])->toBe('000000000000010');
});

it('keeps paging with the returned ultNSU while ultNSU < maxNSU on cStat 138', function () {
    fakeSefaz()->push(
        F::ret138('000000000000002', '000000000000003', [
            F::doc('000000000000001', 'resNFe.xml', 'resNFe_v1.01.xsd'),
            F::doc('000000000000002', 'resEvento.xml', 'resEvento_v1.01.xsd'),
        ]),
        F::ret138('000000000000003', '000000000000003', [
            F::doc('000000000000003', 'procNFe.xml', 'procNFe_v4.00.xsd'),
        ]),
    );

    expect($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate);

    expect(array_column(fakeSefaz()->calls, 'arg'))->toBe(['000000000000000', '000000000000002'])
        ->and(state()->ult_nsu)->toBe('000000000000003')
        ->and(state()->max_nsu)->toBe('000000000000003')
        ->and(state()->next_check_at)->toEqual(now()->addMinutes(65))
        ->and($this->company->sefazRequests()->count())->toBe(2);
});

it('stops after the page limit and resumes on the next run', function () {
    config(['sefaz.max_pages_per_run' => 1]);
    fakeSefaz()->push(F::ret138('000000000000001', '000000000000005', [F::doc('000000000000001', 'resNFe.xml', 'resNFe_v1.01.xsd')]));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::MorePending)
        ->and(state()->next_check_at)->toEqual(now())
        ->and(state()->ult_nsu)->toBe('000000000000001');
});

it('stops and waits one hour on cStat 656, adopting the returned ultNSU', function () {
    fakeSefaz()->push(F::file('ret_656.xml'));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::Blocked);

    expect(state())
        ->ult_nsu->toBe('000000000000042')
        ->consecutive_656->toBe(1)
        ->next_check_at->toEqual(now()->addMinutes(65));

    $this->travel(30)->minutes();
    expect($this->service->sync($this->company))->toBe(SyncOutcome::NotDue)
        ->and(fakeSefaz()->calls)->toHaveCount(1);
});

it('never moves ultNSU backwards', function () {
    $this->company->syncState()->update(['ult_nsu' => '000000000000050']);
    fakeSefaz()->push(F::ret('137', 'Nenhum documento localizado', '000000000000010', '000000000000010'));

    $this->service->sync($this->company);

    expect(state()->ult_nsu)->toBe('000000000000050');
});

it('waits one hour on unexpected rejections too', function () {
    fakeSefaz()->push(F::ret('593', 'Rejeicao: CNPJ-Base consultado difere do CNPJ-Base do Certificado Digital'));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::Failed);

    expect(state())
        ->last_error->toContain('593')
        ->next_check_at->toEqual(now()->addMinutes(65));
});

it('records network errors without advancing NSU and retries later', function () {
    fakeSefaz()->push(SoapException::soapFault('Operation timed out after 30001 milliseconds [/tmp/sped-abc/12345678000195/certs/x.pem]', 28));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::Failed);

    expect(state())
        ->ult_nsu->toBe('000000000000000')
        ->last_error->toContain('timed out')
        ->last_error->not->toContain('/tmp/sped')
        ->next_check_at->toEqual(now()->addMinutes(15));

    expect($this->company->sefazRequests()->sole())
        ->servico->toBe(SefazServico::DistNsu)
        ->cstat->toBeNull()
        ->erro->toContain('timed out');
});

it('rolls back the page when processing fails so it is fetched again', function () {
    fakeSefaz()->push(F::ret138('000000000000001', '000000000000001', [
        ['nsu' => '000000000000001', 'schema' => 'resNFe_v1.01.xsd', 'xml' => '<resNFe><quebrado'],
    ]));

    expect(fn () => $this->service->sync($this->company))->toThrow(Exception::class);

    expect(state()->ult_nsu)->toBe('000000000000000')
        ->and($this->company->documents()->count())->toBe(0);
});

it('shares the cooldown between companies with the same CNPJ', function () {
    $twin = Company::factory()->create(['cnpj' => '12345678000195']);
    fakeSefaz()->push(F::file('ret_137.xml'));

    $this->service->sync($this->company);

    expect($this->service->sync($twin))->toBe(SyncOutcome::NotDue)
        ->and($twin->syncState()->refresh()->next_check_at)->toEqual(now()->addMinutes(65))
        ->and(fakeSefaz()->calls)->toHaveCount(1);
});

it('allows only one sync per CNPJ at a time', function () {
    $lock = Cache::lock('sefaz-dist:2:12345678000195', 60);
    $lock->get();

    expect($this->service->sync($this->company))->toBe(SyncOutcome::Locked)
        ->and(fakeSefaz()->calls)->toBe([]);

    $lock->release();
});

it('stores documents and events from a 138 page', function () {
    fakeSefaz()->push(F::ret138('000000000000003', '000000000000003', [
        F::doc('000000000000001', 'resNFe.xml', 'resNFe_v1.01.xsd'),
        F::doc('000000000000002', 'procNFe.xml', 'procNFe_v4.00.xsd'),
        F::doc('000000000000003', 'procEventoNFe_cancelamento.xml', 'procEventoNFe_v1.00.xsd'),
    ]));

    $this->service->sync($this->company);

    $doc = $this->company->documents()->sole();
    expect($doc)
        ->schema->toBe(DfeSchema::ProcNFe)
        ->tem_xml_completo->toBeTrue()
        ->situacao->toBe(Situacao::Cancelada)
        ->events->toHaveCount(1);
});

it('waits one hour on non-network errors such as schema validation', function () {
    fakeSefaz()->push(new ValidatorException('Este XML não é válido.'));

    $this->service->sync($this->company);

    expect(state()->next_check_at)->toEqual(now()->addMinutes(65));
});
