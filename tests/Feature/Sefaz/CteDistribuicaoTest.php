<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Enums\DfeSchema;
use App\Enums\DocumentoTipo;
use App\Enums\SefazServico;
use App\Enums\Situacao;
use App\Enums\TipoEvento;
use App\Jobs\SyncCompanyDfe;
use App\Models\Company;
use App\Services\Sefaz\DistribuicaoService;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use App\Services\Sefaz\SyncOutcome;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SefazFixtures as F;
use Tests\Support\TestCertificate;

const CHAVE_CTE = '42261011222333000181570010000004561123456780';

beforeEach(function () {
    Storage::fake('local');
    $this->freezeSecond();
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
    $this->service = app(DistribuicaoService::class);
});

it('creates NF-e and CT-e sync states for every new company', function () {
    expect($this->company->syncStates()->pluck('tipo')->all())->toEqualCanonicalizing([DocumentoTipo::Nfe, DocumentoTipo::Cte]);
});

it('downloads CT-e through CTeDistribuicaoDFe and stores them as complete documents', function () {
    fakeSefaz()->push(F::ret138('000000000000002', '000000000000002', [
        F::doc('000000000000001', 'procCTe.xml', 'procCTe_v4.00.xsd'),
        F::doc('000000000000002', 'procEventoCTe-cancelamento.xml', 'procEventoCTe_v4.00.xsd'),
    ]));

    expect($this->service->sync($this->company, DocumentoTipo::Cte))->toBe(SyncOutcome::UpToDate);

    $cte = $this->company->documents()->sole();
    $state = $this->company->syncState(DocumentoTipo::Cte)->refresh();

    expect(fakeSefaz()->calls)->toBe([['method' => 'distNsuCte', 'arg' => '000000000000000']])
        ->and($cte)
        ->tipo->toBe(DocumentoTipo::Cte)
        ->chave->toBe(CHAVE_CTE)
        ->schema->toBe(DfeSchema::ProcCTe)
        ->emitente_nome->toBe('TRANSPORTADORA EXEMPLO LTDA')
        ->valor_total->toBe('350.00')
        ->tem_xml_completo->toBeTrue()
        ->situacao->toBe(Situacao::Cancelada)
        ->and($cte->events()->sole()->tp_evento)->toBe('110111')
        ->and(Storage::disk('local')->get($cte->xml_path))->toBe(F::file('procCTe.xml'))
        ->and($state->ult_nsu)->toBe('000000000000002')
        ->and($state->next_check_at)->toEqual(now()->addMinutes(65))
        ->and($this->company->sefazRequests()->sole()->servico)->toBe(SefazServico::DistNsuCte);
});

it('keeps CT-e waits and locks independent from NF-e', function () {
    fakeSefaz()->push(F::ret('656', 'Rejeicao: Consumo Indevido'), F::file('ret_137.xml'));

    expect($this->service->sync($this->company, DocumentoTipo::Cte))->toBe(SyncOutcome::Blocked)
        // O bloqueio do CT-e não impede a consulta de NF-e, que é outro serviço na SEFAZ.
        ->and($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate)
        ->and($this->company->syncState(DocumentoTipo::Cte)->refresh()->consecutive_656)->toBe(1)
        ->and($this->company->syncState(DocumentoTipo::Nfe)->refresh()->consecutive_656)->toBe(0);
});

it('dispatches one job per due company and document type', function () {
    Queue::fake();
    app(StoreCompanyCertificate::class)->handle($this->company, TestCertificate::pfx(), '1234');

    $this->artisan('sefaz:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SyncCompanyDfe::class, 2);
    Queue::assertPushed(SyncCompanyDfe::class, fn ($job) => $job->tipo === DocumentoTipo::Cte && $job->uniqueId() === "{$this->company->id}:cte");
});

it('never offers recipient manifestation for CT-e', function () {
    fakeSefaz()->push(F::ret138('000000000000001', '000000000000001', [F::doc('000000000000001', 'procCTe.xml', 'procCTe_v4.00.xsd')]));
    $this->service->sync($this->company, DocumentoTipo::Cte);
    $cte = $this->company->documents()->sole();

    expect(app(ManifestacaoService::class)->disponiveis($cte))->toBe([])
        ->and(fn () => app(ManifestacaoService::class)->manifestar($cte, TipoEvento::ConfirmacaoOperacao))->toThrow(ManifestacaoException::class, 'só existe para NF-e');
});
