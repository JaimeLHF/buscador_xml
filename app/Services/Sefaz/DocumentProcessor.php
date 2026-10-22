<?php

namespace App\Services\Sefaz;

use App\Enums\DfeSchema;
use App\Enums\DocumentoTipo;
use App\Enums\ManifestacaoStatus;
use App\Enums\Situacao;
use App\Enums\TipoEvento;
use App\Jobs\SendCienciaOperacao;
use App\Models\Company;
use App\Models\DfeDocument;
use App\Models\DfeEvent;
use App\Services\Sefaz\Exceptions\SefazException;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;

/** Extrai metadados de cada docZip e persiste documento/evento. Idempotente: reprocessar o mesmo NSU não duplica. */
class DocumentProcessor
{
    public function __construct(private XmlStorage $storage) {}

    public function process(Company $company, DocZip $doc): void
    {
        if ($doc->schema === null) {
            // Cada NSU só é entregue uma vez: schemas ainda não tratados (ex.: resCTe) são guardados para processamento futuro.
            $this->storage->putUnsupported($company, $doc->nsu, $doc->rawSchema, $doc->xml);
            Log::info('Schema de docZip não suportado (XML guardado)', ['company_id' => $company->id, 'schema' => $doc->rawSchema, 'nsu' => $doc->nsu]);

            return;
        }

        $dom = new DOMDocument;
        $root = @$dom->loadXML($doc->xml, LIBXML_NONET) ? $dom->documentElement : null;

        if ($root === null) {
            throw SefazException::invalidResponse();
        }

        // chave e tpEvento compõem caminhos de arquivo: só valores no formato oficial são aceitos.
        $chave = $this->chave($root);
        $tpEvento = $this->text($root, 'tpEvento');

        if (! preg_match('/^\d{44}$/', trim($chave)) || ($doc->schema->isEvent() && ! preg_match('/^\d{6}$/', trim((string) $tpEvento)))) {
            Log::warning('docZip com chave ou tpEvento fora do formato', ['company_id' => $company->id, 'nsu' => $doc->nsu, 'schema' => $doc->rawSchema]);

            return;
        }

        match ($doc->schema) {
            DfeSchema::ResNFe => $this->resumo($company, $doc, $root),
            DfeSchema::ProcNFe => $this->completo($company, $doc, $root),
            DfeSchema::ProcCTe, DfeSchema::ProcCTeOS => $this->completoCte($company, $doc, $root),
            DfeSchema::ResEvento, DfeSchema::ProcEventoNFe, DfeSchema::ProcEventoCTe => $this->evento($company, $doc, $root),
        };
    }

    private function resumo(Company $company, DocZip $doc, DOMElement $root): void
    {
        $chave = $this->text($root, 'chNFe');
        $document = $company->documents()->firstOrNew(['chave' => $chave]);
        $path = $this->storage->putDocument($company, DocumentoTipo::Nfe->value, $chave, DfeSchema::ResNFe->value, $doc->xml);

        // Nunca rebaixar um documento que já tem o XML completo.
        if ($document->exists && $document->tem_xml_completo) {
            $document->update(['resumo_xml_path' => $path]);

            return;
        }

        $document->fill([
            'tipo' => DocumentoTipo::Nfe,
            'nsu' => $doc->nsu,
            'schema' => DfeSchema::ResNFe,
            'emitente_cnpj' => $this->text($root, 'CNPJ') ?? $this->text($root, 'CPF'),
            'emitente_nome' => $this->text($root, 'xNome'),
            'data_emissao' => $this->date($this->text($root, 'dhEmi')),
            'valor_total' => $this->text($root, 'vNF'),
            'situacao' => $this->situacao($company, $chave, Situacao::fromCSitNFe((string) $this->text($root, 'cSitNFe'))),
            'tem_xml_completo' => false,
            'resumo_xml_path' => $path,
        ])->save();

        $this->linkOrphanEvents($company, $document);
        $this->requestCiencia($company, $document);
    }

    /** Só o resumo chegou: com a manifestação automática ligada, a Ciência libera o XML completo. */
    private function requestCiencia(Company $company, DfeDocument $document): void
    {
        if ($document->manifestacao_status !== null) {
            return;
        }

        // Manifestação nossa que chegou antes do resumo (ou feita fora do sistema) já define o status.
        $proprio = $company->events()->where('chave', $document->chave)->where('direcao', 'enviado')
            ->whereIn('tp_evento', array_map(fn (TipoEvento $t) => $t->value, TipoEvento::manifestacoes()))
            ->latest('data_evento')->first();

        if ($proprio) {
            $document->update(['manifestacao_status' => ManifestacaoStatus::fromEvento(TipoEvento::from($proprio->tp_evento))]);

            return;
        }

        if (! $company->manifestacao_automatica || $document->situacao !== Situacao::Autorizada || ! $document->cienciaNoPrazo()) {
            return;
        }

        $document->update(['manifestacao_status' => ManifestacaoStatus::CienciaPendente]);

        // afterCommit: o job só existe se a página inteira da distribuição for gravada.
        SendCienciaOperacao::dispatch($document)->afterCommit();
    }

    private function completo(Company $company, DocZip $doc, DOMElement $root): void
    {
        $chave = $this->text($root, 'chNFe') ?? substr((string) $this->attribute($root, 'infNFe', 'Id'), 3);
        $emit = $root->getElementsByTagName('emit')->item(0);
        $ide = $root->getElementsByTagName('ide')->item(0);
        $total = $root->getElementsByTagName('ICMSTot')->item(0);
        $protocolo = $root->getElementsByTagName('infProt')->item(0);

        $document = $company->documents()->firstOrNew(['chave' => $chave]);

        $document->fill([
            'tipo' => DocumentoTipo::Nfe,
            'nsu' => $doc->nsu,
            'schema' => DfeSchema::ProcNFe,
            'emitente_cnpj' => $emit ? ($this->text($emit, 'CNPJ') ?? $this->text($emit, 'CPF')) : null,
            'emitente_nome' => $emit ? $this->text($emit, 'xNome') : null,
            'data_emissao' => $ide ? $this->date($this->text($ide, 'dhEmi') ?? $this->text($ide, 'dEmi')) : null,
            'valor_total' => $total ? $this->text($total, 'vNF') : null,
            'situacao' => $this->situacao($company, $chave, Situacao::fromProtocolo($protocolo ? (string) $this->text($protocolo, 'cStat') : '100')),
            'tem_xml_completo' => true,
            'xml_path' => $this->storage->putDocument($company, DocumentoTipo::Nfe->value, $chave, DfeSchema::ProcNFe->value, $doc->xml),
        ])->save();

        $this->linkOrphanEvents($company, $document);
    }

    /** CT-e e CT-e OS chegam sempre completos (não há resumo nem manifestação para liberar o XML). */
    private function completoCte(Company $company, DocZip $doc, DOMElement $root): void
    {
        $chave = $this->chave($root);
        $emit = $root->getElementsByTagName('emit')->item(0);
        $ide = $root->getElementsByTagName('ide')->item(0);
        $prest = $root->getElementsByTagName('vPrest')->item(0);
        $protocolo = $root->getElementsByTagName('infProt')->item(0);

        $document = $company->documents()->firstOrNew(['chave' => $chave]);

        $document->fill([
            'tipo' => DocumentoTipo::Cte,
            'nsu' => $doc->nsu,
            'schema' => $doc->schema,
            'emitente_cnpj' => $emit ? ($this->text($emit, 'CNPJ') ?? $this->text($emit, 'CPF')) : null,
            'emitente_nome' => $emit ? $this->text($emit, 'xNome') : null,
            'data_emissao' => $ide ? $this->date($this->text($ide, 'dhEmi')) : null,
            'valor_total' => $prest ? $this->text($prest, 'vTPrest') : null,
            'situacao' => $this->situacao($company, $chave, Situacao::fromProtocolo($protocolo ? (string) $this->text($protocolo, 'cStat') : '100')),
            'tem_xml_completo' => true,
            'xml_path' => $this->storage->putDocument($company, DocumentoTipo::Cte->value, $chave, $doc->schema->value, $doc->xml),
        ])->save();

        $this->linkOrphanEvents($company, $document);
    }

    private function evento(Company $company, DocZip $doc, DOMElement $root): void
    {
        // No procEventoNFe/procEventoCTe os dados estão em evento/infEvento; o retorno (cStat/nProt) em retEvento/infEvento.
        $info = ($root->getElementsByTagName('evento')->item(0) ?? $root->getElementsByTagName('eventoCTe')->item(0))?->getElementsByTagName('infEvento')->item(0) ?? $root;
        $retorno = ($root->getElementsByTagName('retEvento')->item(0) ?? $root->getElementsByTagName('retEventoCTe')->item(0))?->getElementsByTagName('infEvento')->item(0);

        $chave = (string) ($this->text($info, 'chNFe') ?? $this->text($info, 'chCTe'));
        $tpEvento = (string) $this->text($info, 'tpEvento');
        $seq = (int) ($this->text($info, 'nSeqEvento') ?? 1);
        $cstat = $retorno ? $this->text($retorno, 'cStat') : null;

        $document = $company->documents()->where('chave', $chave)->first();
        $event = $company->events()->firstOrNew(['chave' => $chave, 'tp_evento' => $tpEvento, 'n_seq_evento' => $seq]);

        // Um resEvento nunca substitui o procEventoNFe (completo) já recebido.
        if ($event->exists && $event->schema->isComplete() && ! $doc->schema->isComplete()) {
            return;
        }

        $tipo = TipoEvento::tryFrom($tpEvento);
        $autor = $this->text($info, 'CNPJ') ?? $this->text($info, 'CPF');
        $proprio = $tipo?->isManifestacao() && $autor !== null && Cnpj::isValid($autor) && Cnpj::from($autor)->root() === $company->cnpjValue()->root();

        $event->fill([
            'dfe_document_id' => $document?->id,
            'nsu' => $doc->nsu,
            'schema' => $doc->schema,
            'descricao' => $this->text($info, 'descEvento') ?? $this->text($info, 'xEvento'),
            'autor_cnpj' => $autor,
            'data_evento' => $this->date($this->text($info, 'dhEvento')),
            'protocolo' => $retorno ? $this->text($retorno, 'nProt') : $this->text($info, 'nProt'),
            'cstat' => $cstat,
            // Manifestação nossa que volta pela distribuição continua marcada como enviada.
            'direcao' => $proprio || $event->direcao === 'enviado' ? 'enviado' : 'recebido',
            'xml_path' => $this->storage->putEvent($company, $chave, $tpEvento, $seq, $doc->schema->value, $doc->xml),
        ])->save();

        if ($document && $this->isValidCancellation($event)) {
            $document->update(['situacao' => Situacao::Cancelada]);
        }

        if ($document && $proprio && ! $document->manifestacao_status?->isConclusive()) {
            $document->update(['manifestacao_status' => ManifestacaoStatus::fromEvento($tipo)]);
        }
    }

    /** Evento de cancelamento já registrado pode chegar antes do documento. */
    private function situacao(Company $company, string $chave, Situacao $fromDocument): Situacao
    {
        $cancelled = $company->events()->where('chave', $chave)->get()->contains(fn (DfeEvent $e) => $this->isValidCancellation($e));

        return $cancelled ? Situacao::Cancelada : $fromDocument;
    }

    private function isValidCancellation(DfeEvent $event): bool
    {
        return (bool) TipoEvento::tryFrom($event->tp_evento)?->cancelaDocumento()
            && ($event->cstat === null || in_array($event->cstat, ['135', '136', '155'], true));
    }

    private function linkOrphanEvents(Company $company, DfeDocument $document): void
    {
        $company->events()->where('chave', $document->chave)->whereNull('dfe_document_id')->update(['dfe_document_id' => $document->id]);
    }

    /**
     * Chave de acesso de NF-e ou CT-e. O Id do infNFe/infCte vem primeiro: um CT-e pode citar chaves de outros
     * documentos (chCTe de documentos anteriores) antes do próprio protocolo.
     */
    private function chave(DOMElement $root): string
    {
        $id = $this->attribute($root, 'infNFe', 'Id') ?: $this->attribute($root, 'infCte', 'Id');

        return trim((string) ($id ? substr($id, 3) : ($this->text($root, 'chNFe') ?? $this->text($root, 'chCTe'))));
    }

    private function text(DOMElement $parent, string $tag): ?string
    {
        $node = $parent->getElementsByTagName($tag)->item(0);

        return $node ? trim($node->textContent) : null;
    }

    private function attribute(DOMElement $parent, string $tag, string $attribute): ?string
    {
        $node = $parent->getElementsByTagName($tag)->item(0);

        return $node instanceof DOMElement ? $node->getAttribute($attribute) : null;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value)->utc() : null;
    }
}
