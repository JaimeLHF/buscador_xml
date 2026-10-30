<?php

use App\Enums\DfeSchema;
use App\Enums\OrganizationRole;
use App\Jobs\SendWebhook;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use App\Services\Webhooks\WebhookUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;

const CHAVE_NFE = '42261011222333000181550010000001231123456788';

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->withOrganization()->create();
    $this->org = $this->user->currentOrganization;
    $this->company = Company::factory()->for($this->org)->create(['cnpj' => '12345678000195']);
    [$this->token, $this->plain] = ApiToken::issue($this->org, 'ERP', $this->user);
});

function receive(Company $company, string $file = 'procNFe.xml', string $schema = 'procNFe_v4.00.xsd', DfeSchema $s = DfeSchema::ProcNFe): void
{
    app(DocumentProcessor::class)->process($company, new DocZip('1', $schema, $s, F::file($file)));
}

it('rejects missing, wrong and revoked tokens', function () {
    $this->getJson('/api/v1/documents')->assertUnauthorized();
    $this->withToken('bxml_errado')->getJson('/api/v1/documents')->assertUnauthorized();

    $this->token->update(['revoked_at' => now()]);
    $this->withToken($this->plain)->getJson('/api/v1/documents')->assertUnauthorized();
});

it('lists only the token organization documents, with a cursor and change filter', function () {
    receive($this->company);
    $foreign = Company::factory()->create(['cnpj' => '12345678000195']);
    receive($foreign);
    $this->travel(3)->minutes();

    $response = $this->withToken($this->plain)->getJson('/api/v1/documents?por_pagina=10')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray(['chave' => CHAVE_NFE, 'tipo' => 'nfe', 'empresa_cnpj' => '12345678000195', 'xml_completo' => true])
        ->and($response->json('proximo_cursor'))->toBeNull()
        ->and($this->token->refresh()->last_used_at)->not->toBeNull();

    $this->withToken($this->plain)->getJson('/api/v1/documents?alterados_desde='.urlencode(now()->addMinute()->toIso8601String()))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('returns one document with its events and the raw XML, auditing the download', function () {
    receive($this->company);

    $this->withToken($this->plain)->getJson('/api/v1/documents/'.CHAVE_NFE)->assertOk()->assertJsonPath('data.chave', CHAVE_NFE)->assertJsonPath('data.eventos', []);
    $this->withToken($this->plain)->get('/api/v1/documents/'.CHAVE_NFE.'/xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $this->withToken($this->plain)->getJson('/api/v1/documents/'.str_repeat('1', 44))->assertNotFound();

    expect(AuditLog::sole())->user_id->toBeNull()->dados->toBe(['token' => 'ERP']);
});

it('signs and posts a webhook after a new note is stored', function () {
    Queue::fake([SendWebhook::class]);
    $this->org->update(['webhook_url' => 'https://8.8.8.8/hook', 'webhook_secret' => 'segredo']);
    $this->company->refresh();

    receive($this->company);

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->evento === 'documento.recebido');

    Http::fake(['*' => Http::response('', 204)]);
    $job = Queue::pushedJobs()[SendWebhook::class][0]['job'];
    $job->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://8.8.8.8/hook'
            && $request->header('X-Buscador-Assinatura')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'segredo')
            && $request['evento'] === 'documento.recebido'
            && $request['documento']['chave'] === CHAVE_NFE;
    });
    expect($this->org->refresh()->webhook_ultimo_status)->toBe('HTTP 204');
});

it('notifies the cancellation and stays quiet without a webhook', function () {
    Queue::fake([SendWebhook::class]);
    receive($this->company);
    Queue::assertNothingPushed();

    $this->org->update(['webhook_url' => 'https://8.8.8.8/hook', 'webhook_secret' => 'segredo']);
    $this->company->refresh();
    receive($this->company, 'procEventoNFe_cancelamento.xml', 'procEventoNFe_v1.00.xsd', DfeSchema::ProcEventoNFe);

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->evento === 'documento.cancelado');
});

it('refuses webhook addresses on internal networks or without https', function (string $url) {
    expect(fn () => WebhookUrl::resolve($url))->toThrow(InvalidArgumentException::class);
})->with(['http://8.8.8.8/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://169.254.169.254/latest', 'https://user:pass@8.8.8.8/x', 'ftp://8.8.8.8']);

it('shows the organization keys read-only to managers, without creating or revoking, and hides the page from members', function () {
    [, $plain] = ApiToken::issueForCompany($this->company, 'Costhub', null);

    Livewire::actingAs($this->user)->test('pages::organizations.integrations')
        ->assertSee('Costhub')
        ->assertDontSee($plain)
        ->assertDontSee('Criar chave')
        ->assertDontSee('Revogar');

    expect(fn () => Livewire::actingAs($this->user)->test('pages::organizations.integrations')->call('criarToken'))
        ->toThrow(Exception::class);

    $member = User::factory()->create();
    $this->org->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->org);
    $this->actingAs($member)->get(route('organizations.integrations'))->assertForbidden();
});

it('saves the webhook with a generated secret and rejects internal addresses', function () {
    Livewire::actingAs($this->user)->test('pages::organizations.integrations')
        ->set('webhookUrl', 'https://10.1.1.1/hook')
        ->call('salvarWebhook')
        ->assertHasErrors('webhookUrl')
        ->set('webhookUrl', 'https://8.8.8.8/hook')
        ->call('salvarWebhook')
        ->assertHasNoErrors();

    expect($this->org->refresh())->webhook_url->toBe('https://8.8.8.8/hook')
        ->and(strlen((string) $this->org->webhook_secret))->toBe(48);
});
