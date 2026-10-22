<?php

use App\Actions\Companies\StoreCompanyCertificate;
use App\Actions\Organizations\StoreOfficeCertificate;
use App\Enums\Ambiente;
use App\Enums\DfeSchema;
use App\Enums\ModoBusca;
use App\Enums\OrganizationRole;
use App\Enums\SefazServico;
use App\Enums\Situacao;
use App\Enums\TipoEvento;
use App\Enums\Uf;
use App\Jobs\SyncCompanyDfe;
use App\Jobs\SyncCompanySefSc;
use App\Models\Company;
use App\Models\User;
use App\Services\Certificates\Exceptions\InvalidCertificateException;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use App\Services\Sefaz\ManifestacaoService;
use App\Services\Sefaz\SyncOutcome;
use App\Services\SefSc\SefScDistribuicaoService;
use App\Services\SefSc\SefScSoapGateway;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeSefScGateway as SC;
use Tests\Support\SefazFixtures as F;
use Tests\Support\TestCertificate;

const ESCRITORIO = '11444777000161';

beforeEach(function () {
    Storage::fake('local');
    $this->freezeSecond();
    // O serviço da SEF-SC só existe em produção; o gateway falso garante que nada sai do teste.
    config(['sefaz.allow_production' => true]);
    $this->user = User::factory()->withOrganization()->create();
    $this->org = $this->user->currentOrganization;
    $this->company = Company::factory()->for($this->org)->create([
        'cnpj' => '12345678000195', 'uf' => Uf::SC, 'ambiente' => Ambiente::Producao, 'modo_busca' => ModoBusca::ContadorSc,
    ]);
    $this->travel(1)->minutes(); // o certificado de teste nasce válido a partir de "agora"
    app(StoreOfficeCertificate::class)->handle($this->org, TestCertificate::pfx(ESCRITORIO), '1234');
    $this->service = app(SefScDistribuicaoService::class);
});

it('builds the official request: company CNPJ, full XML, issued and received notes', function () {
    $pedido = SefScSoapGateway::pedido($this->company, '1500');

    expect($pedido)->toContain('<CNPJ>12345678000195</CNPJ>')
        ->toContain('<indXML>1</indXML><indAtor>3</indAtor><ultNuNSU>1500</ultNuNSU>')
        ->toContain('xmlns="http://www.satnfe.sef.sc.gov.br/ws/distribuicao-v2"');
});

it('downloads notes and events with the office certificate, then waits 12 hours', function () {
    fakeSefSc()->push(SC::ret('118', 'DF-e localizados', [
        ['nsu' => 7, 'xml' => F::file('procNFe.xml')],
        ['nsu' => 8, 'xml' => F::file('procEventoNFe_cancelamento.xml')],
    ], ultNsu: 8));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate);

    $doc = $this->company->documents()->sole();
    $state = $this->company->sefScState();

    expect(fakeSefSc()->calls)->toBe([['cnpj' => '12345678000195', 'ultNsu' => '0', 'certificado' => ESCRITORIO]])
        ->and($doc)->schema->toBe(DfeSchema::ProcNFe)->tem_xml_completo->toBeTrue()->situacao->toBe(Situacao::Cancelada)->nsu->toBe('7')
        ->and($state->ult_nsu)->toBe('8')
        ->and($state->next_check_at)->toEqual(now()->addMinutes(730))
        ->and($this->company->sefazRequests()->sole())->servico->toBe(SefazServico::DownloadSefSc)->documentos->toBe(2)
        // O NSU do Ambiente Nacional fica intacto: são sequências diferentes.
        ->and($this->company->syncState()->ult_nsu)->toBe('000000000000000');
});

it('asks for the next batch right away when the batch is full', function () {
    config(['sef_sc.lote_maximo' => 1]);
    fakeSefSc()->push(
        SC::ret('118', 'DF-e localizados', [['nsu' => 1, 'xml' => F::file('procNFe.xml')]], ultNsu: 1),
        SC::ret('117', 'Nenhum DF-e localizado para distribuição'),
    );

    expect($this->service->sync($this->company))->toBe(SyncOutcome::UpToDate)
        ->and(array_column(fakeSefSc()->calls, 'ultNsu'))->toBe(['0', '1']);
});

it('explains in plain words when the office is not the company accountant', function () {
    fakeSefSc()->push(SC::ret('8002', 'Rejeição: Requisitante não é Contabilista do CNPJ/CPF informado'));

    expect($this->service->sync($this->company))->toBe(SyncOutcome::Failed);

    $state = $this->company->sefScState();
    expect($state->last_error)->toContain('não está cadastrado na SEF-SC como contador desta empresa')
        ->and($state->next_check_at)->toEqual(now()->addMinutes(730));
});

it('retries in one hour when SEF-SC is reprocessing', function () {
    fakeSefSc()->push(SC::ret('110', 'DFe em reprocessamento, tente novamente mais tarde.'));

    $this->service->sync($this->company);

    expect($this->company->sefScState()->next_check_at)->toEqual(now()->addMinutes(60));
});

it('never queries SEF-SC before the wait ends or when something is missing', function (array $attrs, string $motivo) {
    $this->company->update($attrs);

    expect(SefScDistribuicaoService::impedimento($this->company->refresh()))->toContain($motivo)
        ->and($this->service->sync($this->company))->toBe(SyncOutcome::NotDue)
        ->and(fakeSefSc()->calls)->toBe([]);
})->with([
    'fora de SC' => [['uf' => Uf::PR], 'Santa Catarina'],
    'homologação' => [['ambiente' => Ambiente::Homologacao], 'só existe em produção'],
]);

it('dispatches each company to its own search mode', function () {
    Queue::fake();
    $own = Company::factory()->for($this->org)->create(['cnpj' => '11222333000181']);
    app(StoreCompanyCertificate::class)->handle($own, TestCertificate::pfx('11222333000181'), '1234');

    $this->artisan('sefaz:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SyncCompanySefSc::class, fn ($job) => $job->company->is($this->company));
    Queue::assertPushed(SyncCompanyDfe::class, fn ($job) => $job->company->is($own));
    Queue::assertNotPushed(SyncCompanyDfe::class, fn ($job) => $job->company->is($this->company));
});

it('accepts the accountant e-CPF as office certificate', function () {
    $cert = app(StoreOfficeCertificate::class)->handle($this->org, TestCertificate::pfx(cpf: '52998224725'), '1234');

    expect($cert)->cnpj_certificado->toBe('52998224725')->company_id->toBeNull()->organization_id->toBe($this->org->id)
        ->and($cert->documentoFormatado())->toBe('529.982.247-25')
        ->and($this->org->refresh()->officeCertificate->is($cert))->toBeTrue()
        ->and(fn () => app(StoreOfficeCertificate::class)->handle($this->org, TestCertificate::pfx(withCnpj: false), '1234'))
        ->toThrow(InvalidCertificateException::class);
});

it('blocks manifestation without the company own certificate and on notes the company issued', function () {
    app(DocumentProcessor::class)->process($this->company, new DocZip('1', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe, F::file('procNFe.xml')));
    $doc = $this->company->documents()->sole();

    expect(fn () => app(ManifestacaoService::class)->manifestar($doc, TipoEvento::ConfirmacaoOperacao))
        ->toThrow(ManifestacaoException::class, 'certificado digital da própria empresa');

    $issuer = Company::factory()->for($this->org)->create(['cnpj' => '11222333000181']);
    app(DocumentProcessor::class)->process($issuer, new DocZip('1', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe, F::file('procNFe.xml')));

    expect(app(ManifestacaoService::class)->disponiveis($issuer->documents()->sole()))->toBe([]);
});

it('lets the form pick the accountant mode only for SC companies and forces production', function () {
    $form = Livewire::actingAs($this->user)->test('pages::companies.form')
        ->set('cnpj', '11.222.333/0001-81')
        ->set('razao_social', 'EMPRESA PR')
        ->set('uf', 'PR')
        ->set('modo_busca', 'contador_sc')
        ->call('save')
        ->assertHasErrors('modo_busca');

    $form->set('uf', 'SC')->set('ambiente', Ambiente::Homologacao->value)->set('modo_busca', 'contador_sc')->call('save')->assertHasNoErrors();

    expect(Company::where('cnpj', '11222333000181')->sole())->modo_busca->toBe(ModoBusca::ContadorSc)->ambiente->toBe(Ambiente::Producao);
});

it('shows the mode and the office certificate on the company screens', function () {
    Livewire::actingAs($this->user)->test('pages::companies.index')
        ->assertSee('Certificado do contador (empresas de SC)')
        ->assertSee('Contador (SC)');

    Livewire::actingAs($this->user)->test('pages::companies.show', ['company' => $this->company])
        ->assertSee('Busca: Certificado do contador (só SC)')
        ->assertSee('EMPRESA TESTE LTDA');

    $member = User::factory()->create();
    $this->org->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->org);
    $this->actingAs($member)->post(route('office-certificate.store'))->assertForbidden();
});
