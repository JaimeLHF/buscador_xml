<?php

use App\Enums\DfeSchema;
use App\Enums\Situacao;
use App\Models\Company;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\DocZip;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SefazFixtures as F;

const CHAVE = '42261011222333000181550010000001231123456788';

beforeEach(function () {
    Storage::fake('local');
    $this->company = Company::factory()->create(['cnpj' => '12345678000195']);
    $this->processor = app(DocumentProcessor::class);
});

function docZip(string $fixture, string $schema, string $nsu = '000000000000001'): DocZip
{
    return new DocZip($nsu, $schema, DfeSchema::fromDocZip($schema), F::file($fixture));
}

it('extracts metadata from resNFe and stores the original XML byte for byte', function () {
    $this->processor->process($this->company, docZip('resNFe.xml', 'resNFe_v1.01.xsd'));

    $doc = $this->company->documents()->sole();

    expect($doc)
        ->chave->toBe(CHAVE)
        ->schema->toBe(DfeSchema::ResNFe)
        ->emitente_cnpj->toBe('11222333000181')
        ->emitente_nome->toBe('FORNECEDOR EXEMPLO LTDA')
        ->valor_total->toBe('1500.75')
        ->situacao->toBe(Situacao::Autorizada)
        ->tem_xml_completo->toBeFalse()
        ->xml_path->toBeNull();

    expect($doc->data_emissao->toIso8601String())->toBe('2026-10-20T13:15:00+00:00')
        ->and(Storage::disk('local')->get($doc->resumo_xml_path))->toBe(F::file('resNFe.xml'))
        ->and($doc->resumo_xml_path)->toBe("dfe/{$this->company->id}/nfe/2026/10/".CHAVE.'-resNFe.xml');
});

it('promotes the summary to the full document when procNFe arrives', function () {
    $this->processor->process($this->company, docZip('resNFe.xml', 'resNFe_v1.01.xsd'));
    $this->processor->process($this->company, docZip('procNFe.xml', 'procNFe_v4.00.xsd', '000000000000002'));

    $doc = $this->company->documents()->sole();

    expect($doc)
        ->schema->toBe(DfeSchema::ProcNFe)
        ->tem_xml_completo->toBeTrue()
        ->nsu->toBe('000000000000002')
        ->resumo_xml_path->not->toBeNull()
        ->and(Storage::disk('local')->get($doc->xml_path))->toBe(F::file('procNFe.xml'));
});

it('does not downgrade a full document when a late summary arrives', function () {
    $this->processor->process($this->company, docZip('procNFe.xml', 'procNFe_v4.00.xsd', '000000000000002'));
    $this->processor->process($this->company, docZip('resNFe.xml', 'resNFe_v1.01.xsd', '000000000000003'));

    expect($this->company->documents()->sole())
        ->schema->toBe(DfeSchema::ProcNFe)
        ->tem_xml_completo->toBeTrue();
});

it('cancels the document when a cancellation event arrives, before or after it', function (bool $eventFirst) {
    $steps = [
        fn () => $this->processor->process($this->company, docZip('procNFe.xml', 'procNFe_v4.00.xsd')),
        fn () => $this->processor->process($this->company, docZip('procEventoNFe_cancelamento.xml', 'procEventoNFe_v1.00.xsd', '000000000000002')),
    ];

    foreach ($eventFirst ? array_reverse($steps) : $steps as $step) {
        $step();
    }

    $doc = $this->company->documents()->sole();
    $event = $this->company->events()->sole();

    expect($doc->situacao)->toBe(Situacao::Cancelada)
        ->and($event)
        ->dfe_document_id->toBe($doc->id)
        ->tp_evento->toBe('110111')
        ->cstat->toBe('135')
        ->protocolo->toBe('342260000000999')
        ->autor_cnpj->toBe('11222333000181')
        ->descricao->toBe('Cancelamento');
})->with(['document first' => false, 'event first' => true]);

it('stores resEvento without a matching document', function () {
    $this->processor->process($this->company, docZip('resEvento.xml', 'resEvento_v1.01.xsd'));

    expect($this->company->events()->sole())
        ->tp_evento->toBe('110110')
        ->dfe_document_id->toBeNull()
        ->protocolo->toBe('342260000001111')
        ->schema->toBe(DfeSchema::ResEvento);
});

it('is idempotent when the same NSU is processed twice', function () {
    foreach ([1, 2] as $_) {
        $this->processor->process($this->company, docZip('procNFe.xml', 'procNFe_v4.00.xsd'));
        $this->processor->process($this->company, docZip('procEventoNFe_cancelamento.xml', 'procEventoNFe_v1.00.xsd'));
    }

    expect($this->company->documents()->count())->toBe(1)
        ->and($this->company->events()->count())->toBe(1);
});

it('ignores unknown schemas', function () {
    $this->processor->process($this->company, new DocZip('000000000000001', 'resCTe_v1.00.xsd', null, '<resCTe/>'));

    expect($this->company->documents()->count())->toBe(0);
});
