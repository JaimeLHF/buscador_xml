<?php

use App\Enums\ChaveHistoricaStatus;
use App\Enums\NaturezaCompra;
use App\Enums\OrganizationRole;
use App\Jobs\BuscarChavesHistoricas;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;

/** Chave da fixture procNFe.xml. */
const CHAVE_SPED_TELA = '42261011222333000181550010000001231123456788';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config(['services.meudanfe.key' => 'teste']);
    $this->user = User::factory()->withOrganization()->create();
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195']);
});

function arquivoSped(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('sped.txt', implode("\r\n", [
        '|C100|0|1|F1|55|00|1|123|'.CHAVE_SPED_TELA.'|20102026|20102026|1500,75|',
        '|C100|0|1|F1|55|00|1|124|'.chaveApi(124).'|05102026|05102026|80,00|',
        '|C100|0|1|F1|55|00|1|125|'.chaveApi(125).'|06102026|06102026|90,00|',
    ]));
}

it('reads the SPED as soon as it is dropped and lists the keys awaiting selection, without fetching anything', function () {
    Queue::fake();
    notaApi($this->company, chaveApi(125), ['emitente_nome' => 'FORNECEDOR CONHECIDO LTDA']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('speds', [arquivoSped()])
        ->assertHasNoErrors()
        ->assertSet('status', 'importada')
        ->assertSee('3 notas de entrada no SPED')
        ->assertSee('1 já tinham o XML · 2 novas aguardando seleção')
        ->assertSee('2311 2345 6788')
        ->assertSee('R$ 80,00')
        ->assertDontSee('R$ 90,00');

    expect($this->company->chavesHistoricas()->where('status', 'importada')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('fetches only the marked keys from Meu Danfe and audits the paid request', function () {
    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('speds', [arquivoSped()]);

    Http::fake([
        'https://api.meudanfe.com.br/v2/fd/add/*' => Http::response(['status' => 'OK']),
        'https://api.meudanfe.com.br/v2/fd/get/xml/*' => Http::response(['format' => 'XML', 'data' => F::file('procNFe.xml')]),
    ]);

    $marcada = $this->company->chavesHistoricas()->where('chave', CHAVE_SPED_TELA)->sole();

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('selecionados', [$marcada->id])
        ->call('consultar');

    expect($marcada->refresh()->status)->toBe(ChaveHistoricaStatus::Encontrada)
        ->and($this->company->documents()->where('chave', CHAVE_SPED_TELA)->sole()->xml_reconstruido)->toBeTrue()
        ->and($this->company->chavesHistoricas()->where('status', 'importada')->count())->toBe(2)
        ->and(AuditLog::sole()->dados)->toBe(['chaves' => 1]);

    Http::assertSentCount(2);
});

it('ignores marked keys that are not selectable or belong to another company', function () {
    Queue::fake();
    $outra = Company::factory()->create();
    $alheia = $outra->chavesHistoricas()->create(['chave' => chaveApi(1), 'status' => 'importada']);
    $achada = $this->company->chavesHistoricas()->create(['chave' => chaveApi(2), 'status' => 'encontrada']);
    $nova = $this->company->chavesHistoricas()->create(['chave' => chaveApi(3), 'status' => 'importada']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('selecionados', [$alheia->id, $achada->id, $nova->id])
        ->call('consultar');

    expect($alheia->refresh()->status)->toBe(ChaveHistoricaStatus::Importada)
        ->and($achada->refresh()->status)->toBe(ChaveHistoricaStatus::Encontrada)
        ->and($nova->refresh()->status)->toBe(ChaveHistoricaStatus::Pendente);
    Queue::assertPushed(BuscarChavesHistoricas::class, 1);
});

it('marks every selectable key of the current filter', function () {
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(1), 'status' => 'importada', 'sped_data' => '2026-09-10']);
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(2), 'status' => 'erro', 'sped_data' => '2026-10-10']);
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(3), 'status' => 'encontrada', 'sped_data' => '2026-10-11']);
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(4), 'status' => 'importada', 'sped_data' => '2026-10-12']);

    $page = Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('mes', '2026-10')
        ->call('marcarFiltradas');

    $page->assertSet('todas', true)->assertSee('2 chaves marcadas');
});

it('keeps members without management rights out', function () {
    $member = User::factory()->create();
    $this->company->organization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->company->organization);

    $this->actingAs($member)->get(route('companies.sped', $this->company))->assertForbidden();
    $this->actingAs(User::factory()->withOrganization()->create())->get(route('companies.sped', $this->company))->assertForbidden();
    $this->actingAs($this->user)->get(route('companies.sped', $this->company))->assertOk()->assertSee('Chaves do SPED');
});

it('hands the hourly run to the single fetch job only when there is a queue', function () {
    Queue::fake();

    $this->artisan('historico:buscar')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->company->chavesHistoricas()->create(['chave' => chaveApi(1), 'status' => 'pendente']);
    $this->artisan('historico:buscar')->assertSuccessful();
    Queue::assertPushed(BuscarChavesHistoricas::class, 1);
});

it('never shows the name of the lookup provider to the user', function () {
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(1), 'status' => 'importada']);
    Http::fake(['*' => Http::response('falhou', 500)]);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('selecionados', [$this->company->chavesHistoricas()->sole()->id])
        ->call('consultar')
        ->assertSee('Falha na consulta (HTTP 500).')
        ->assertDontSee('Meu Danfe')
        ->assertDontSee('meudanfe');
});

/** C100 completo do leiaute do EFD ICMS/IPI, com fornecedor (COD_PART), situação e impostos. */
function c100(string $part, string $sit, string $num, string $chave, string $valor, string $icms = '0', string $ipi = '0'): string
{
    // REG|IND_OPER|IND_EMIT|COD_PART|COD_MOD|COD_SIT|SER|NUM_DOC|CHV_NFE|DT_DOC|DT_E_S|VL_DOC|IND_PGTO|VL_DESC|VL_ABAT_NT|VL_MERC|IND_FRT|VL_FRT|VL_SEG|VL_OUT_DA|VL_BC_ICMS|VL_ICMS|VL_BC_ICMS_ST|VL_ICMS_ST|VL_IPI|VL_PIS|VL_COFINS|VL_PIS_ST|VL_COFINS_ST
    return "|C100|0|1|{$part}|55|{$sit}|1|{$num}|{$chave}|05102026|06102026|{$valor}|1|0|0|{$valor}|9|0|0|0|{$valor}|{$icms}|0|0|{$ipi}|0|0|0|0|";
}

it('reads supplier, purchase nature, SPED status and taxes, and never offers a note cancelled in the SPED', function () {
    Queue::fake();
    // Arquivo em Latin-1, como o PVA grava.
    $sped = mb_convert_encoding(implode("\r\n", [
        '|0150|F1|INDÚSTRIA DE MÓVEIS SÃO JOÃO LTDA|1058|11222333000181||255|4205407||RUA|1|||',
        '|0150|F2|POSTO CENTRAL LTDA|1058|11444777000161||255|4314902||RUA|1|||',
        c100('F1', '00', '124', chaveApi(124), '1000,00', '120,00', '50,00'),
        '|C190|000|1102|12,00|200,00|1000,00|120,00|0|0|0|0||',
        '|C190|000|1101|12,00|800,00|1000,00|0|0|0|0|0||',
        c100('F2', '02', '125', chaveApi(125), '300,00'),
        '|C190|060|1653|0|300,00|0|0|0|0|0|0||',
    ]), 'ISO-8859-1', 'UTF-8');

    $page = Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('speds', [UploadedFile::fake()->createWithContent('sped.txt', $sped)])
        ->assertSee('INDÚSTRIA DE MÓVEIS SÃO JOÃO LTDA')
        ->assertSee('SC')
        ->assertSee('Industrialização')
        ->assertSee('CFOP 1101')
        ->assertSee('ICMS R$ 120,00 · IPI R$ 50,00')
        ->assertSee('Cancelada no SPED');

    $industria = $this->company->chavesHistoricas()->where('chave', chaveApi(124))->sole();
    $cancelada = $this->company->chavesHistoricas()->where('chave', chaveApi(125))->sole();
    expect($industria->fornecedor_uf)->toBe('SC')
        ->and($industria->natureza)->toBe(NaturezaCompra::Industrializacao)
        ->and($industria->sped_impostos)->toBe(['icms' => '120.00', 'ipi' => '50.00'])
        ->and($cancelada->natureza)->toBe(NaturezaCompra::Combustivel);

    $page->call('marcarFiltradas')->assertSet('todas', true)->assertSee('1 chave marcada')
        ->set('natureza', 'combustivel')->assertSee('POSTO CENTRAL LTDA')->assertDontSee('SÃO JOÃO');

    // Mesmo forçando a seleção pelo navegador, a cancelada no SPED não vai para a consulta.
    $page->set('selecionados', [$cancelada->id])->call('consultar');
    expect($cancelada->refresh()->status)->toBe(ChaveHistoricaStatus::Importada);
    Queue::assertNothingPushed();
});

it('selects every key of the filter, beyond one page and without a cap, and queues them all', function () {
    Queue::fake();
    $this->company->chavesHistoricas()->createMany(collect(range(1, 1203))->map(fn ($n) => ['chave' => chaveApi($n), 'status' => 'importada', 'sped_data' => '2026-10-10'])->all());
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(9999), 'status' => 'importada', 'sped_situacao' => '02']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->call('marcarFiltradas')
        ->assertSee('1.203 chaves marcadas')
        ->call('consultar');

    expect($this->company->chavesHistoricas()->where('status', 'pendente')->count())->toBe(1203);
    Queue::assertPushed(BuscarChavesHistoricas::class, 1);
});

it('drops the select-all mode when the filter changes', function () {
    $this->company->chavesHistoricas()->create(['chave' => chaveApi(1), 'status' => 'importada']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->call('marcarFiltradas')->assertSet('todas', true)
        ->set('mes', '2026-01')->assertSet('todas', false);
});

it('finds a supplier by name and selects only its notes', function () {
    Queue::fake();
    $eucatex = '56643018000166';
    $daEucatex = fn ($n) => '4225'.'10'.$eucatex.'55001'.str_pad((string) $n, 9, '0', STR_PAD_LEFT).'1123456780';
    $this->company->chavesHistoricas()->create(['chave' => $daEucatex(1), 'status' => 'importada', 'fornecedor_nome' => 'EUCATEX S/A INDUSTRIA E COMERCIO']);
    $this->company->chavesHistoricas()->create(['chave' => $daEucatex(2), 'status' => 'importada', 'fornecedor_nome' => 'EUCATEX S/A INDUSTRIA E COMERCIO']);
    $outra = $this->company->chavesHistoricas()->create(['chave' => chaveApi(3), 'status' => 'importada', 'fornecedor_nome' => 'OUTRO FORNECEDOR LTDA']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('q', 'eucatex')
        ->assertSee('Selecionar todas (2)')
        ->assertDontSee('OUTRO FORNECEDOR')
        ->set('q', '')
        ->call('filtrarFornecedor', $eucatex)
        ->assertSee('Selecionar todas (2)')
        ->call('marcarFiltradas')
        ->call('consultar');

    expect($this->company->chavesHistoricas()->where('status', 'pendente')->count())->toBe(2)
        ->and($outra->refresh()->status)->toBe(ChaveHistoricaStatus::Importada);
});

it('groups the keys by supplier, opens one and marks only its notes to look up', function () {
    Queue::fake();
    $eucatex = '56643018000166';
    $daEucatex = fn ($n) => '422510'.$eucatex.'55001'.str_pad((string) $n, 9, '0', STR_PAD_LEFT).'1123456780';
    $a = $this->company->chavesHistoricas()->create(['chave' => $daEucatex(1), 'status' => 'importada', 'fornecedor_nome' => 'EUCATEX S/A', 'sped_valor' => '100.00']);
    $b = $this->company->chavesHistoricas()->create(['chave' => $daEucatex(2), 'status' => 'erro', 'fornecedor_nome' => 'EUCATEX S/A', 'sped_valor' => '50.00']);
    $this->company->chavesHistoricas()->create(['chave' => $daEucatex(3), 'status' => 'encontrada', 'fornecedor_nome' => 'EUCATEX S/A']);
    $this->company->chavesHistoricas()->create(['chave' => $daEucatex(4), 'status' => 'importada', 'fornecedor_nome' => 'EUCATEX S/A', 'sped_situacao' => '02']);
    $outra = $this->company->chavesHistoricas()->create(['chave' => chaveApi(5), 'status' => 'importada', 'fornecedor_nome' => 'OUTRO FORNECEDOR LTDA']);

    $page = Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('visao', 'fornecedores')
        ->assertSee('EUCATEX S/A')
        ->assertSee('2 a consultar')
        ->assertSee('1 com XML')
        ->assertSee('Selecionar (2)')
        ->call('alternarFornecedor', $eucatex)
        ->assertSee('NF 1')
        ->assertSee('Cancelada no SPED')
        ->call('marcarFornecedor', $eucatex)
        ->assertSee('Marcadas');

    expect(array_map('intval', $page->get('selecionados')))->toEqualCanonicalizing([$a->id, $b->id]);

    // Clicar de novo desmarca só as desse fornecedor.
    $page->set('selecionados', [$a->id, $b->id, $outra->id])->call('marcarFornecedor', $eucatex);
    expect(array_map('intval', $page->get('selecionados')))->toBe([$outra->id]);
});
