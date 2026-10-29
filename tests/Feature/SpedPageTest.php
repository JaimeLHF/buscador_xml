<?php

use App\Enums\ChaveHistoricaStatus;
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

it('reads the uploaded SPED and lists the keys awaiting selection, without fetching anything', function () {
    Queue::fake();
    notaApi($this->company, chaveApi(125), ['emitente_nome' => 'FORNECEDOR CONHECIDO LTDA']);

    Livewire::actingAs($this->user)->test('pages::companies.sped', ['company' => $this->company])
        ->set('speds', [arquivoSped()])
        ->call('importar')
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
        ->set('speds', [arquivoSped()])->call('importar');

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

    expect($page->get('selecionados'))->toHaveCount(2);
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
