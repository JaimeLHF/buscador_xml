<?php

use App\Models\ApiToken;
use App\Models\ApiTokenAcesso;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['services.admin.emails' => 'Admin@FinderXML.test, outro@finderxml.test']);

    $this->admin = User::factory()->withOrganization()->create(['email' => 'admin@finderxml.test']);
    $this->cliente = User::factory()->withOrganization()->create();
    $this->org = $this->cliente->currentOrganization;
    $this->ruiz = Company::factory()->for($this->org)->create(['cnpj' => '24287808000160', 'razao_social' => 'MOVEIS RUIZ LTDA']);
    $this->outra = Company::factory()->for($this->org)->create(['cnpj' => '12345678000195', 'razao_social' => 'EMPRESA X LTDA']);
});

it('hides the admin panel from everyone outside ADMIN_EMAILS', function () {
    $this->get(route('admin.conexoes.index'))->assertRedirect(route('login'));
    $this->actingAs($this->cliente)->get(route('admin.conexoes.index'))->assertNotFound();

    $this->actingAs($this->admin)->get(route('admin.conexoes.index'))->assertOk()->assertSee('Nova conexão');

    // O atalho no menu do usuário só aparece para o admin.
    $this->actingAs($this->admin)->get(route('documents.index'))->assertSee(route('admin.conexoes.index'));
    $this->actingAs($this->cliente)->get(route('documents.index'))->assertDontSee(route('admin.conexoes.index'));
});

it('creates a connection bound to one company and shows the key only once', function () {
    $page = Livewire::actingAs($this->admin)->test('pages::admin.conexoes')
        ->call('abrirNovaConexao')
        ->assertSet('passo', 1)
        ->call('escolherOrganizacao', $this->org->id)
        ->assertSet('passo', 2)
        ->assertSee('EMPRESA X LTDA')
        ->call('escolherEmpresa', $this->ruiz->id)
        ->assertSet('passo', 3)
        ->set('nome', 'Costhub')
        ->call('criar')
        ->assertHasNoErrors();

    $plain = $page->get('chaveGerada');
    $token = ApiToken::findActive($plain);

    expect($plain)->toStartWith('bxml_')
        ->and($token->company_id)->toBe($this->ruiz->id)
        ->and($token->organization_id)->toBe($this->org->id)
        ->and($token->token_hash)->not->toBe($plain);

    $page->call('fecharChave')->assertSet('chaveGerada', null);
    Livewire::actingAs($this->admin)->test('pages::admin.conexoes')->assertSee('MOVEIS RUIZ LTDA')->assertDontSee($plain);
});

it('lets a company connection read only that company notes', function () {
    [, $plain] = ApiToken::issueForCompany($this->ruiz, 'Costhub', null);
    notaApi($this->ruiz, chaveApi(1));
    notaApi($this->outra, chaveApi(2));
    $this->travel(3)->minutes();

    expect($this->withToken($plain)->getJson('/api/v1/documents')->assertOk()->json('data.*.chave'))->toBe([chaveApi(1)])
        ->and($this->withToken($plain)->getJson('/api/v1/documents?empresa_cnpj=12345678000195')->assertOk()->json('data'))->toBe([]);

    $this->withToken($plain)->getJson('/api/v1/documents/'.chaveApi(1))->assertOk();
    $this->withToken($plain)->getJson('/api/v1/documents/'.chaveApi(2))->assertNotFound();
    $this->withToken($plain)->getJson('/api/v1/documents/'.chaveApi(2).'/xml')->assertNotFound();

    // A cobertura dispensa o CNPJ e não abre o de outra empresa.
    expect($this->withToken($plain)->getJson('/api/v1/cobertura?de=2026-10&ate=2026-10')->assertOk()->json('empresa_cnpj'))->toBe('24287808000160')
        ->and($this->withToken($plain)->getJson('/api/v1/cobertura?empresa_cnpj=12345678000195&de=2026-10&ate=2026-10')->json('data'))->toBe([]);
});

it('tells the system which company the key belongs to', function () {
    [, $plain] = ApiToken::issueForCompany($this->ruiz, 'Costhub', null);

    $this->withToken($plain)->getJson('/api/v1/conexao')->assertOk()->assertJson(['data' => [
        'conexao' => 'Costhub',
        'escopo' => 'empresa',
        'empresas' => [['cnpj' => '24287808000160', 'razao_social' => 'MOVEIS RUIZ LTDA']],
    ]])->assertJsonCount(1, 'data.empresas');
});

it('records every call with what left, and shows it on the connection page', function () {
    [$token, $plain] = ApiToken::issueForCompany($this->ruiz, 'Costhub', null);
    notaApi($this->ruiz, chaveApi(1));
    notaApi($this->ruiz, chaveApi(3));
    $this->travel(3)->minutes();

    $this->withToken($plain)->getJson('/api/v1/documents')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/documents/'.chaveApi(1))->assertOk();
    $this->withToken($plain)->getJson('/api/v1/documents/'.chaveApi(9))->assertNotFound();

    $acessos = $token->acessos()->orderBy('id')->get();
    expect($acessos->pluck('rota')->all())->toBe(['documents.index', 'documents.show', 'documents.show'])
        ->and($acessos->pluck('quantidade')->all())->toBe([2, 1, null])
        ->and($acessos->pluck('status')->all())->toBe([200, 200, 404])
        ->and($acessos[1]->chave)->toBe(chaveApi(1))
        ->and($token->fresh()->total_chamadas)->toBe(3)
        ->and($token->fresh()->ultimo_ip)->toBe('127.0.0.1');

    Livewire::actingAs($this->admin)->test('pages::admin.conexao', ['token' => $token])
        ->assertSee('Abriu uma nota')
        ->assertSee(chaveApi(1))
        ->set('filtro', 'erros')
        ->assertSee('404')
        ->assertDontSee('Listou notas');
});

it('revokes a connection and issues a new key for the same company', function () {
    [$token, $plain] = ApiToken::issueForCompany($this->ruiz, 'Costhub', null);

    $page = Livewire::actingAs($this->admin)->test('pages::admin.conexoes')->call('novaChave', $token->id);
    $nova = $page->get('chaveGerada');

    $this->withToken($plain)->getJson('/api/v1/conexao')->assertUnauthorized();
    expect(ApiToken::findActive($nova)->company_id)->toBe($this->ruiz->id);

    $page->call('revogar', ApiToken::findActive($nova)->id);
    $this->withToken($nova)->getJson('/api/v1/conexao')->assertUnauthorized();
});

it('does not accept a company outside the chosen organization in the modal', function () {
    $alheia = Company::factory()->create();

    Livewire::actingAs($this->admin)->test('pages::admin.conexoes')
        ->call('escolherOrganizacao', $this->org->id)
        ->call('escolherEmpresa', $alheia->id)
        ->assertNotFound();

    Livewire::actingAs($this->admin)->test('pages::admin.conexoes')
        ->set('organizationId', $this->org->id)
        ->set('companyId', $alheia->id)
        ->call('criar')
        ->assertNotFound();

    expect(ApiToken::count())->toBe(0);
});

it('refuses to bind a key to a company of another organization', function () {
    ApiToken::issue($this->org, 'Costhub', null, Company::factory()->create());
})->throws(HttpException::class);

it('prunes call records older than the retention period', function () {
    [$token] = ApiToken::issueForCompany($this->ruiz, 'Costhub', null);
    $velho = $token->acessos()->create(['rota' => 'conexao', 'status' => 200]);
    $velho->forceFill(['created_at' => now()->subDays(ApiTokenAcesso::DIAS_GUARDADOS + 1)])->save();
    $novo = $token->acessos()->create(['rota' => 'conexao', 'status' => 200]);

    $this->artisan('model:prune', ['--model' => [ApiTokenAcesso::class]])->assertSuccessful();

    expect(ApiTokenAcesso::pluck('id')->all())->toBe([$novo->id]);
});
