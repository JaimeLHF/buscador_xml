<?php

use App\Enums\DfeSchema;
use App\Enums\ExportStatus;
use App\Enums\OrganizationRole;
use App\Enums\Situacao;
use App\Jobs\BuildXmlExport;
use App\Models\Company;
use App\Models\DfeDocument;
use App\Models\Export;
use App\Models\User;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use App\Services\Sefaz\XmlStorage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195', 'razao_social' => 'Minha Empresa']);
    app(DocumentProcessor::class)->process($this->company, new DocZip('1', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe, F::file('procNFe.xml')));
    $this->document = $this->company->documents()->sole();
});

function makeDoc(Company $company, array $attrs): DfeDocument
{
    return $company->documents()->create([
        'tipo' => 'nfe', 'nsu' => '000000000000009', 'schema' => DfeSchema::ResNFe, 'situacao' => Situacao::Autorizada,
        'chave' => str_pad((string) random_int(1, 999999), 44, '4', STR_PAD_LEFT), ...$attrs,
    ]);
}

it('filters documents by every supported criterion', function (array $filters, int $expected) {
    makeDoc($this->company, ['emitente_nome' => 'OUTRO FORNECEDOR', 'emitente_cnpj' => '44333222000191', 'valor_total' => 50, 'data_emissao' => '2026-09-01 12:00:00', 'situacao' => Situacao::Cancelada]);

    $component = Livewire::actingAs($this->user)->test('pages::documents.index');
    foreach ($filters as $key => $value) {
        $component->set($key, $value);
    }

    expect($component->get('documents')->total())->toBe($expected);
})->with([
    'sem filtros' => [[], 2],
    'emitente por nome' => [['emitente' => 'exemplo'], 1],
    'emitente por CNPJ' => [['emitente' => '44.333'], 1],
    'valor mínimo' => [['valor_min' => '1000'], 1],
    'valor máximo' => [['valor_max' => '100'], 1],
    'período' => [['de' => '2026-10-01', 'ate' => '2026-10-31'], 1],
    'situação' => [['situacao' => 'cancelada'], 1],
    'com XML completo' => [['xml' => 'completo'], 1],
    'somente resumo' => [['xml' => 'resumo'], 1],
    'chave' => [['chave' => '4226101122233300018155'], 1],
    'sem manifestação' => [['manifestacao' => 'nenhuma'], 2],
]);

it('never lists documents from other organizations', function () {
    $foreign = Company::factory()->create();
    makeDoc($foreign, ['emitente_nome' => 'SEGREDO DE OUTRO CLIENTE']);

    $this->actingAs($this->user)->get(route('documents.index'))
        ->assertOk()
        ->assertSee('FORNECEDOR EXEMPLO LTDA')
        ->assertDontSee('SEGREDO DE OUTRO CLIENTE');

    Livewire::actingAs($this->user)->test('pages::documents.index')->set('company_id', (string) $foreign->id)
        ->assertDontSee('SEGREDO DE OUTRO CLIENTE');
});

it('downloads the original XML and renders the DANFE', function () {
    $this->actingAs($this->user)->get(route('documents.xml', $this->document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertDownload($this->document->chave.'-procNFe.xml');

    expect($this->actingAs($this->user)->get(route('documents.xml', $this->document))->getContent())->toBe(F::file('procNFe.xml'));

    $pdf = $this->actingAs($this->user)->get(route('documents.danfe', $this->document));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 5))->toBe('%PDF-');
});

it('refuses DANFE for summaries and files from other organizations', function () {
    $resumo = makeDoc($this->company, []);
    $this->actingAs($this->user)->from('https://evil.example.com')->get(route('documents.danfe', $resumo))
        ->assertRedirect(route('documents.show', $resumo))->assertSessionHas('error');

    $stranger = User::factory()->withOrganization()->create();
    $this->actingAs($stranger)->get(route('documents.xml', $this->document))->assertForbidden();
    $this->actingAs($stranger)->get(route('documents.danfe', $this->document))->assertForbidden();
});

it('queues a ZIP export with the current filters and builds it', function () {
    Queue::fake();
    makeDoc($this->company, ['emitente_nome' => 'OUTRO', 'resumo_xml_path' => null]);

    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->set('xml', 'completo')
        ->call('exportar')
        ->assertRedirect(route('exports.index'));

    $export = Export::sole();
    expect($export->filters['xml'])->toBe('completo');
    Queue::assertPushed(BuildXmlExport::class);

    (new BuildXmlExport($export))->handle(app(XmlStorage::class));

    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Done)->and($export->total)->toBe(1);

    $zip = new ZipArchive;
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, Storage::disk('local')->get($export->path));
    $zip->open($tmp);
    expect($zip->getFromName("12345678000195/{$this->document->chave}-procNFe.xml"))->toBe(F::file('procNFe.xml'));
    $zip->close();
    unlink($tmp);

    $this->actingAs($this->user)->get(route('exports.download', $export))->assertOk()->assertDownload();
});

it('only lets the requester download an export', function () {
    $export = $this->user->currentOrganization->exports()->create(['user_id' => $this->user->id, 'filters' => [], 'status' => ExportStatus::Done, 'path' => 'exports/x.zip']);
    Storage::disk('local')->put('exports/x.zip', 'zip');

    $colleague = User::factory()->create();
    $this->user->currentOrganization->addMember($colleague, OrganizationRole::Admin);
    $colleague->switchOrganization($this->user->currentOrganization);

    $this->actingAs($colleague)->get(route('exports.download', $export))->assertForbidden();
});

it('counts the filters hidden behind the mobile toggle', function () {
    $user = User::factory()->withOrganization()->create();

    Livewire::actingAs($user)->test('pages::documents.index')
        ->assertSet('filtrosAtivos', 0)
        ->set('emitente', 'ACME')
        ->assertSet('filtrosAtivos', 0)
        ->set('xml', 'resumo')
        ->set('situacao', 'autorizada')
        ->assertSet('filtrosAtivos', 2);
});
