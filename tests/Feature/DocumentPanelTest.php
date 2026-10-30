<?php

use App\Enums\DfeSchema;
use App\Enums\ExportFormato;
use App\Enums\ExportStatus;
use App\Enums\OrganizationRole;
use App\Enums\Situacao;
use App\Jobs\BuildCsvReport;
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
    'busca por fornecedor' => [['q' => 'exemplo'], 1],
    'busca por CNPJ' => [['q' => '44.333'], 1],
    'valor mínimo' => [['valor_min' => '1000'], 1],
    'valor máximo' => [['valor_max' => '100'], 1],
    'período' => [['de' => '2026-10-01', 'ate' => '2026-10-31'], 1],
    'aba canceladas' => [['aba' => 'canceladas'], 1],
    'aba sem XML não repete as canceladas' => [['aba' => 'sem_xml'], 0],
    'busca por chave' => [['q' => '4226101122233300018155'], 1],
    'sem manifestação' => [['manifestacao' => 'nenhuma'], 2],
]);

it('never lists documents from other organizations', function () {
    $foreign = Company::factory()->create();
    makeDoc($foreign, ['emitente_nome' => 'SEGREDO DE OUTRO CLIENTE']);

    $this->actingAs($this->user)->get(route('documents.index'))
        ->assertOk()
        ->assertSee('FORNECEDOR EXEMPLO LTDA')
        ->assertDontSee('SEGREDO DE OUTRO CLIENTE');

    // Mesmo com a empresa de outra organização gravada no usuário, nada vaza.
    $this->user->forceFill(['current_company_id' => $foreign->id])->save();

    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->assertSee('FORNECEDOR EXEMPLO LTDA')
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
        ->set('q', 'exemplo')
        ->call('exportar')
        ->assertRedirect(route('exports.index'));

    $export = Export::sole();
    expect($export->filters['busca'])->toBe('exemplo');
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

it('builds a spreadsheet of the filtered notes that Excel opens without mangling keys or formulas', function () {
    Queue::fake();
    makeDoc($this->company, ['emitente_nome' => '=HYPERLINK("http://x")', 'valor_total' => 1234.5, 'situacao' => Situacao::Cancelada]);

    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->call('exportar', 'csv')
        ->assertRedirect(route('exports.index'));

    $export = Export::sole();
    Queue::assertPushed(BuildCsvReport::class);
    (new BuildCsvReport($export))->handle();

    $csv = Storage::disk('local')->get($export->refresh()->path);
    $linhas = explode("\n", trim($csv));

    expect($export)->formato->toBe(ExportFormato::Csv)->total->toBe(2)
        ->and($csv)->toStartWith("\u{FEFF}Tipo;")
        ->and($linhas)->toHaveCount(3)
        ->and($csv)->toContain('"=""'.$this->document->chave.'"""')
        ->toContain("'=HYPERLINK")
        ->toContain('1234,50')
        ->toContain('Cancelada');

    $this->actingAs($this->user)->get(route('exports.download', $export))->assertOk()->assertDownload('notas-'.$export->created_at->format('Ymd-His').'.csv');
});

it('only lets the requester download an export', function () {
    $export = $this->user->currentOrganization->exports()->create(['user_id' => $this->user->id, 'filters' => [], 'status' => ExportStatus::Done, 'path' => 'exports/x.zip']);
    Storage::disk('local')->put('exports/x.zip', 'zip');

    $colleague = User::factory()->create();
    $this->user->currentOrganization->addMember($colleague, OrganizationRole::Admin);
    $colleague->switchOrganization($this->user->currentOrganization);

    $this->actingAs($colleague)->get(route('exports.download', $export))->assertForbidden();
});

it('counts only the filters kept in the filters drawer', function () {
    Livewire::actingAs($this->user)->test('pages::documents.index')
        ->assertSet('filtrosAtivos', 0)
        ->set('q', 'ACME')
        ->set('aba', 'sem_xml')
        ->assertSet('filtrosAtivos', 0)
        ->set('de', '2026-10-01')
        ->set('valor_min', '100')
        ->assertSet('filtrosAtivos', 2)
        ->call('limparFiltros')
        ->assertSet('filtrosAtivos', 0)
        ->assertSet('q', 'ACME');
});

it('splits the inbox into tabs and searches supplier or access key in one field', function () {
    makeDoc($this->company, ['emitente_nome' => 'SÓ RESUMO LTDA', 'tem_xml_completo' => false]);
    makeDoc($this->company, ['emitente_nome' => 'CANCELADA SA', 'situacao' => Situacao::Cancelada]);

    $page = Livewire::actingAs($this->user)->test('pages::documents.index')
        ->assertSee('XML pronto')
        ->assertSet('contagens', ['sem_xml' => 1, 'canceladas' => 1]);

    $page->set('aba', 'sem_xml')->assertSee('SÓ RESUMO LTDA')->assertDontSee('CANCELADA SA');
    $page->set('aba', 'canceladas')->assertSee('CANCELADA SA')->assertDontSee('SÓ RESUMO LTDA');
    $page->set('aba', '')->set('q', substr($this->document->chave, 0, 20))->assertSee(route('documents.show', $this->document), false)->assertDontSee('CANCELADA SA');
});

it('shows the full XML content on the document page', function () {
    Livewire::actingAs($this->user)->test('pages::documents.show', ['document' => $this->document])
        ->assertSee('NF-e nº 123, série 1')
        ->assertSee('VENDA DE MERCADORIA')
        ->assertSee('PRODUTO TESTE')
        ->assertSee('84719012')
        ->assertSee('Total da nota')
        ->assertSee('CEP 88000-000')
        ->assertSee('&lt;infNFe', false);
});
