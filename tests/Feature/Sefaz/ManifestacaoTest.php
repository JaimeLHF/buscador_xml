<?php

use App\Enums\DfeSchema;
use App\Enums\ManifestacaoStatus;
use App\Enums\OrganizationRole;
use App\Enums\SefazServico;
use App\Enums\Situacao;
use App\Enums\TipoEvento;
use App\Jobs\FetchFullDocument;
use App\Jobs\SendCienciaOperacao;
use App\Models\Company;
use App\Models\DfeDocument;
use App\Models\User;
use App\Services\Sefaz\ConsultaChaveService;
use App\Services\Sefaz\ConsultaLimiter;
use App\Services\Sefaz\DistribuicaoService;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use NFePHP\Common\Exception\SoapException;
use Tests\Support\SefazFixtures as F;

const CH = '42261011222333000181550010000001231123456788';

beforeEach(function () {
    Storage::fake('local');
    // Os jobs são executados explicitamente em cada teste; aqui só se verifica o enfileiramento.
    Queue::fake([SendCienciaOperacao::class, FetchFullDocument::class]);
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195', 'manifestacao_automatica' => true]);
});

function receiveResumo(Company $company): DfeDocument
{
    app(DocumentProcessor::class)->process($company, new DocZip('000000000000001', 'resNFe_v1.01.xsd', DfeSchema::ResNFe, F::file('resNFe.xml')));

    return $company->documents()->sole();
}

it('queues the Ciência only after the distribution page is committed', function () {
    fakeSefaz()->push(F::ret138('000000000000001', '000000000000001', [F::doc('000000000000001', 'resNFe.xml', 'resNFe_v1.01.xsd')]));

    app(DistribuicaoService::class)->sync($this->company);

    Queue::assertPushed(SendCienciaOperacao::class, 1);
    expect($this->company->documents()->sole()->manifestacao_status)->toBe(ManifestacaoStatus::CienciaPendente);
});

it('does not send Ciência when automatic manifestation is off, for cancelled notes or full XMLs', function () {
    Queue::fake();
    $this->company->update(['manifestacao_automatica' => false]);
    receiveResumo($this->company);

    $other = Company::factory()->create(['cnpj' => '12345678000195', 'manifestacao_automatica' => true]);
    app(DocumentProcessor::class)->process($other, new DocZip('1', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe, F::file('procNFe.xml')));

    Queue::assertNothingPushed();
});

it('sends the Ciência, stores the procEventoNFe and schedules the full XML fetch', function () {
    $document = receiveResumo($this->company);
    fakeSefaz()->push(F::retEvento('135', 'Evento registrado e vinculado a NF-e', CH));

    (new SendCienciaOperacao($document))->handle(app(ManifestacaoService::class));

    $document->refresh();
    $event = $document->events()->sole();

    expect($document->manifestacao_status)->toBe(ManifestacaoStatus::Ciencia)
        ->and(fakeSefaz()->calls[0])->toBe(['method' => 'manifestar', 'arg' => '210210:'.CH.':1'])
        ->and($event)
        ->tp_evento->toBe('210210')
        ->direcao->toBe('enviado')
        ->protocolo->toBe('891260000012345')
        ->and(Storage::disk('local')->get($event->xml_path))->toContain('<procEventoNFe')->toContain('<nProt>891260000012345</nProt>')
        ->and($this->company->sefazRequests()->sole()->servico)->toBe(SefazServico::Manifestacao);

    Queue::assertPushed(FetchFullDocument::class, fn ($job) => $job->delay !== null);
});

it('fetches the full XML by key after the Ciência', function () {
    $document = receiveResumo($this->company);
    fakeSefaz()->push(F::ret138('000000000000000', '000000000000000', [F::doc('000000000000000', 'procNFe.xml', 'procNFe_v4.00.xsd')]));

    (new FetchFullDocument($document))->handle(app(ConsultaChaveService::class));

    expect($document->refresh()->tem_xml_completo)->toBeTrue();
});

it('leaves the full XML for the next distribution when the hourly key limit is exhausted', function () {
    $document = receiveResumo($this->company);
    $limiter = app(ConsultaLimiter::class);
    foreach (range(1, 20) as $_) {
        $limiter->attempt($this->company);
    }

    (new FetchFullDocument($document))->handle(app(ConsultaChaveService::class));

    expect(fakeSefaz()->calls)->toBe([])
        ->and($document->refresh()->tem_xml_completo)->toBeFalse();
});

it('marks the Ciência as failed on rejection without retrying', function () {
    $document = receiveResumo($this->company);
    fakeSefaz()->push(F::retEvento('596', 'Rejeicao: Evento apresentado apos o prazo permitido para o evento', CH));

    $job = (new SendCienciaOperacao($document))->withFakeQueueInteractions();
    $job->handle(app(ManifestacaoService::class));

    $job->assertFailed();
    expect($document->refresh()->manifestacao_status)->toBe(ManifestacaoStatus::Erro)
        ->and(fakeSefaz()->calls)->toHaveCount(1);
});

it('lets network failures be retried by the queue', function () {
    $document = receiveResumo($this->company);
    fakeSefaz()->push(SoapException::soapFault('Operation timed out', 28));

    (new SendCienciaOperacao($document))->handle(app(ManifestacaoService::class));
})->throws(SoapException::class);

it('treats duplicated events (573) as already registered', function () {
    $document = receiveResumo($this->company);
    fakeSefaz()->push(F::retEvento('573', 'Rejeicao: Duplicidade de evento', CH));

    app(ManifestacaoService::class)->manifestar($document, TipoEvento::CienciaOperacao);

    expect($document->refresh()->manifestacao_status)->toBe(ManifestacaoStatus::Ciencia);
});

it('enforces manifestation rules', function () {
    $document = receiveResumo($this->company);
    $service = app(ManifestacaoService::class);

    expect(fn () => $service->manifestar($document, TipoEvento::OperacaoNaoRealizada, 'curta'))
        ->toThrow(ManifestacaoException::class, 'justificativa');

    $document->update(['manifestacao_status' => ManifestacaoStatus::Confirmada]);
    expect($service->disponiveis($document))->toBe([])
        ->and(fn () => $service->manifestar($document, TipoEvento::DesconhecimentoOperacao))->toThrow(ManifestacaoException::class, 'concluída');

    $document->update(['manifestacao_status' => null, 'situacao' => Situacao::Cancelada]);
    expect($service->disponiveis($document))->toBe([]);

    expect(fakeSefaz()->calls)->toBe([]);
});

it('allows a conclusive manifestation after the Ciência', function () {
    $document = receiveResumo($this->company);
    $document->update(['manifestacao_status' => ManifestacaoStatus::Ciencia]);

    expect(app(ManifestacaoService::class)->disponiveis($document))
        ->toBe([TipoEvento::ConfirmacaoOperacao, TipoEvento::DesconhecimentoOperacao, TipoEvento::OperacaoNaoRealizada]);
});

it('updates the status when our own manifestation comes back through distribution', function () {
    Queue::fake();
    $this->company->update(['manifestacao_automatica' => false]);
    $document = receiveResumo($this->company);
    $xml = str_replace(
        ['<CNPJ>44333222000191</CNPJ>', '42261044333222000191550010000045671123456792', '<tpEvento>110110</tpEvento>'],
        ['<CNPJ>12345678000195</CNPJ>', CH, '<tpEvento>210200</tpEvento>'],
        F::file('resEvento.xml'),
    );

    app(DocumentProcessor::class)->process($this->company, new DocZip('2', 'resEvento_v1.01.xsd', DfeSchema::ResEvento, $xml));

    expect($document->refresh()->manifestacao_status)->toBe(ManifestacaoStatus::Confirmada)
        ->and($document->events()->sole()->direcao)->toBe('enviado');
});

it('lets admins manifest from the document page with confirmation', function () {
    Queue::fake();
    $document = receiveResumo($this->company);
    $document->update(['manifestacao_status' => ManifestacaoStatus::Ciencia]);
    fakeSefaz()->push(F::retEvento('135', 'Evento registrado e vinculado a NF-e', CH, '210240'));

    Livewire::actingAs($this->user)->test('pages::documents.show', ['document' => $document])
        ->assertSee('Operação não Realizada')
        ->call('confirmar', '210240')
        ->set('justificativa', 'Mercadoria devolvida antes da entrega')
        ->call('manifestar')
        ->assertHasNoErrors();

    expect($document->refresh()->manifestacao_status)->toBe(ManifestacaoStatus::NaoRealizada);
});

it('hides manifestation actions from members and other organizations', function () {
    Queue::fake();
    $document = receiveResumo($this->company);

    $member = User::factory()->create();
    $this->user->currentOrganization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->user->currentOrganization);

    Livewire::actingAs($member)->test('pages::documents.show', ['document' => $document])
        ->assertDontSee('Confirmação da Operação')
        ->call('confirmar', '210200')
        ->call('manifestar')
        ->assertForbidden();

    $stranger = User::factory()->withOrganization()->create();
    $this->actingAs($stranger)->get(route('documents.show', $document))->assertForbidden();
});
