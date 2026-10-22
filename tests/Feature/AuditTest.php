<?php

use App\Enums\AuditAcao;
use App\Enums\DfeSchema;
use App\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SefazFixtures as F;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->withOrganization()->create(['name' => 'Ana Dona']);
    $this->company = Company::factory()->for($this->user->currentOrganization)->create(['cnpj' => '12345678000195']);
    app(DocumentProcessor::class)->process($this->company, new DocZip('1', 'procNFe_v4.00.xsd', DfeSchema::ProcNFe, F::file('procNFe.xml')));
    $this->document = $this->company->documents()->sole();
});

it('records who downloaded the XML and opened the DANFE', function () {
    $this->actingAs($this->user)->get(route('documents.xml', $this->document))->assertOk();

    $log = AuditLog::sole();

    expect($log)
        ->acao->toBe(AuditAcao::XmlBaixado)
        ->user_id->toBe($this->user->id)
        ->dfe_document_id->toBe($this->document->id)
        ->organization_id->toBe($this->user->current_organization_id)
        ->ip->toBe('127.0.0.1');
});

it('records exports with their filters', function () {
    Queue::fake();

    Livewire::actingAs($this->user)->test('pages::documents.index')->set('q', 'exemplo')->call('exportar', 'csv');

    expect(AuditLog::sole())
        ->acao->toBe(AuditAcao::ExportacaoPedida)
        ->descricao->toBe('Planilha (CSV)')
        ->and(AuditLog::sole()->dados['filtros'])->toBe(['busca' => 'exemplo']);
});

it('shows the audit trail only to owners and admins, scoped to the organization', function () {
    $this->actingAs($this->user)->get(route('documents.xml', $this->document));
    $other = User::factory()->withOrganization()->create();
    AuditLog::create(['organization_id' => $other->current_organization_id, 'acao' => AuditAcao::XmlBaixado, 'descricao' => 'SEGREDO']);

    Livewire::actingAs($this->user)->test('pages::organizations.audit')
        ->assertSee('Baixou o XML')
        ->assertSee('Ana Dona')
        ->assertDontSee('SEGREDO');

    Livewire::actingAs($this->user)->test('pages::documents.show', ['document' => $this->document])
        ->assertSee('Quem mexeu nesta nota');

    $member = User::factory()->create();
    $this->user->currentOrganization->addMember($member, OrganizationRole::Member);
    $member->switchOrganization($this->user->currentOrganization);

    $this->actingAs($member)->get(route('organizations.audit'))->assertForbidden();
    Livewire::actingAs($member)->test('pages::documents.show', ['document' => $this->document])
        ->assertDontSee('Quem mexeu nesta nota');
});
