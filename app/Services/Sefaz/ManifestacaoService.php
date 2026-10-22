<?php

namespace App\Services\Sefaz;

use App\Enums\DfeSchema;
use App\Enums\DocumentoTipo;
use App\Enums\ManifestacaoStatus;
use App\Enums\SefazServico;
use App\Enums\Situacao;
use App\Enums\TipoEvento;
use App\Models\DfeDocument;
use App\Models\DfeEvent;
use App\Services\Documents\NfeReader;
use App\Services\Sefaz\Exceptions\ManifestacaoException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use NFePHP\CTe\Complements as CteComplements;
use NFePHP\NFe\Complements;
use Throwable;

class ManifestacaoService
{
    public function __construct(
        private SefazGateway $gateway,
        private SefazRequestLogger $logger,
        private XmlStorage $storage,
    ) {}

    /**
     * @throws ManifestacaoException regra de negócio ou rejeição da SEFAZ
     * @throws Throwable falha de comunicação (o chamador decide se tenta de novo)
     */
    public function manifestar(DfeDocument $document, TipoEvento $tipo, string $xJust = ''): DfeEvent
    {
        $this->guard($document, $tipo, $xJust);

        $company = $document->company;
        $seq = $company->events()->where('chave', $document->chave)->where('tp_evento', $tipo->value)->where('direcao', 'enviado')->count() + 1;
        $started = microtime(true);

        try {
            $result = $this->gateway->manifestar($company, $document->chave, $tipo, $seq, trim($xJust));
            $ret = RetEvento::parse($result->response);
        } catch (Throwable $e) {
            $this->logger->logError($company, SefazServico::Manifestacao, $started, $e->getMessage(), chave: $document->chave);

            throw $e;
        }

        $this->logger->logEvento($company, $started, $ret, $document->chave);

        if (! $ret->registrado() && ! $ret->duplicado()) {
            Log::warning('Manifestação rejeitada', ['document_id' => $document->id, 'tp_evento' => $tipo->value, 'cstat' => $ret->cStat]);

            throw ManifestacaoException::rejected($ret->cStat, $ret->xMotivo);
        }

        $cte = $tipo->documentoTipo() === DocumentoTipo::Cte;
        $schema = $cte ? DfeSchema::ProcEventoCTe : DfeSchema::ProcEventoNFe;

        // Duplicidade (573) significa que o evento já existe na SEFAZ: o estado local é atualizado do mesmo jeito.
        $path = $ret->registrado()
            ? $this->storage->putEvent($company, $document->chave, $tipo->value, $seq, $schema->value, $cte
                ? CteComplements::toAuthorize($result->request, $result->response)
                : Complements::toAuthorize($result->request, $result->response))
            : null;

        $event = $company->events()->updateOrCreate(
            ['chave' => $document->chave, 'tp_evento' => $tipo->value, 'n_seq_evento' => $seq],
            [
                'dfe_document_id' => $document->id,
                'schema' => $schema,
                'descricao' => $tipo->label(),
                'autor_cnpj' => $company->cnpj,
                'data_evento' => $ret->dataRegistro ? CarbonImmutable::parse($ret->dataRegistro)->utc() : now(),
                'protocolo' => $ret->protocolo,
                'cstat' => $ret->cStat,
                'direcao' => 'enviado',
                'xml_path' => $path,
            ],
        );

        $document->update(['manifestacao_status' => ManifestacaoStatus::fromEvento($tipo)]);

        return $event;
    }

    /** @return list<TipoEvento> */
    public function disponiveis(DfeDocument $document): array
    {
        return array_values(array_filter(TipoEvento::manifestacoesDe($document->tipo), fn (TipoEvento $tipo) => $this->motivoBloqueio($document, $tipo) === null));
    }

    private function guard(DfeDocument $document, TipoEvento $tipo, string $xJust): void
    {
        if ($reason = $this->motivoBloqueio($document, $tipo)) {
            throw ManifestacaoException::notAllowed($reason);
        }

        $length = mb_strlen(trim($xJust));

        if ($tipo->exigeJustificativa() && ($length < 15 || $length > 255)) {
            throw ManifestacaoException::justificativaInvalida();
        }
    }

    private function motivoBloqueio(DfeDocument $document, TipoEvento $tipo): ?string
    {
        $status = $document->manifestacao_status;

        if ($tipo === TipoEvento::PrestacaoDesacordo) {
            return $this->motivoBloqueioDesacordo($document);
        }

        return match (true) {
            ! $tipo->isManifestacao() => 'Evento não é uma manifestação do destinatário.',
            $document->tipo !== DocumentoTipo::Nfe => 'A manifestação do destinatário só existe para NF-e.',
            $document->situacao === Situacao::Cancelada => 'A NF-e está cancelada.',
            (bool) $status?->isConclusive() => 'A manifestação desta NF-e já foi concluída.',
            $tipo === TipoEvento::CienciaOperacao && $status === ManifestacaoStatus::Ciencia => 'A Ciência da Operação já foi registrada.',
            $tipo === TipoEvento::CienciaOperacao && ! $document->cienciaNoPrazo() => 'O prazo de 10 dias para a Ciência expirou. Use Confirmação, Desconhecimento ou Operação não Realizada.',
            $tipo !== TipoEvento::CienciaOperacao && ! $document->conclusivaNoPrazo() => 'O prazo de 180 dias para a manifestação conclusiva expirou.',
            default => null,
        };
    }

    private function motivoBloqueioDesacordo(DfeDocument $document): ?string
    {
        return match (true) {
            $document->tipo !== DocumentoTipo::Cte => 'O desacordo do serviço só existe para CT-e.',
            $document->situacao === Situacao::Cancelada => 'O CT-e está cancelado.',
            $document->manifestacao_status === ManifestacaoStatus::Desacordo => 'O desacordo deste CT-e já foi registrado.',
            ! $document->desacordoNoPrazo() => 'O prazo de 45 dias para registrar o desacordo expirou.',
            ! $this->ehTomador($document) => 'Só o tomador do serviço pode registrar o desacordo, e esta empresa não é a tomadora deste CT-e.',
            default => null,
        };
    }

    private function ehTomador(DfeDocument $document): bool
    {
        $xml = $document->xml_path ? $this->storage->get($document->xml_path) : null;

        try {
            $tomador = $xml ? (new NfeReader($xml))->tomadorCnpj() : null;
        } catch (\RuntimeException) {
            $tomador = null;
        }

        return $tomador !== null && $tomador === $document->company->cnpj;
    }
}
