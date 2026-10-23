<?php

namespace App\Services\Sefaz;

use App\Enums\CStat;
use App\Enums\DocumentoTipo;
use App\Models\Company;
use App\Models\SyncState;
use App\Notifications\CompanyBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Máquina de estados do NSU (NT 2014.002):
 *  138 → processa e segue enquanto ultNSU < maxNSU; ao alcançar maxNSU, espera 1 hora.
 *  137 → nada novo: espera 1 hora.
 *  656 → consumo indevido: para e espera 1 hora (usando o ultNSU retornado, se houver).
 *  Qualquer outro retorno também espera 1 hora, para nunca provocar um 656.
 *  Falha de comunicação: ver FailureClassifier.
 */
class DistribuicaoService
{
    public function __construct(
        private SefazGateway $gateway,
        private RetDistDFeParser $parser,
        private DocumentProcessor $processor,
        private SefazRequestLogger $logger,
        private CnpjCooldown $cooldown,
    ) {}

    public function sync(Company $company, DocumentoTipo $tipo = DocumentoTipo::Nfe, bool $untilDone = false): SyncOutcome
    {
        // Um único processo por CNPJ/ambiente/serviço por vez (o limite da SEFAZ é por CNPJ, não por cadastro).
        $sufixo = $tipo === DocumentoTipo::Nfe ? '' : ":{$tipo->value}";
        $lock = Cache::lock("sefaz-dist:{$company->ambiente->value}:{$company->cnpj}{$sufixo}", 660);

        if (! $lock->get()) {
            return SyncOutcome::Locked;
        }

        try {
            return $this->run($company, $tipo, $untilDone);
        } finally {
            $lock->release();
        }
    }

    private function run(Company $company, DocumentoTipo $tipo, bool $untilDone = false): SyncOutcome
    {
        $state = $company->syncState($tipo);

        if ($state->next_check_at?->isFuture()) {
            return SyncOutcome::NotDue;
        }

        if ($until = $this->cooldown->until($company, $tipo)) {
            $state->update(['next_check_at' => $until]);

            return SyncOutcome::NotDue;
        }

        $maxPages = $untilDone ? PHP_INT_MAX : config('sefaz.max_pages_per_run');

        for ($page = 0; $page < $maxPages; $page++) {
            $outcome = $this->fetchPage($company, $state, $page + 1);

            if ($outcome !== SyncOutcome::MorePending) {
                return $outcome;
            }
        }

        // Ainda há documentos: aguarda um intervalo curto antes de retomar, evitando 656 por excesso de chamadas.
        $state->update(['next_check_at' => now()->addMinutes(config('sefaz.wait_between_runs_minutes'))]);

        return SyncOutcome::MorePending;
    }

    private function fetchPage(Company $company, SyncState $state, int $pageNumber = 1): SyncOutcome
    {
        $sent = $state->ult_nsu;
        $started = microtime(true);

        try {
            $ret = $this->parser->parse($this->gateway->distNsu($company, $sent, $state->tipo));
        } catch (Throwable $e) {
            return $this->failed($company, $state, $sent, $e, $started);
        }

        $this->logger->log($company, $state->tipo->servicoDistribuicao(), $started, $ret, ultNsuEnviado: $sent);

        $state->fill([
            'consecutive_failures' => 0,
            'last_checked_at' => now(),
            'last_cstat' => $ret->cStat,
            'last_xmotivo' => Str::limit($ret->xMotivo, 250),
            'last_error' => null,
        ]);

        if ($ret->is(CStat::DocumentosLocalizados)) {
            // Documentos e avanço do NSU na mesma transação: se algo falhar, a página é reconsultada inteira.
            DB::transaction(function () use ($company, $state, $ret): void {
                foreach ($ret->documents as $doc) {
                    $this->processor->process($company, $doc);
                }

                $this->advance($state, $ret);
                $state->consecutive_656 = 0;

                if (! $ret->hasMore()) {
                    $state->next_check_at = $this->waitOneHour($company, $state->tipo);
                }

                $state->save();
            });

            SyncProgress::running($company->id, $pageNumber, count($ret->documents), $state->ult_nsu, $state->max_nsu);

            return $ret->hasMore() ? SyncOutcome::MorePending : SyncOutcome::UpToDate;
        }

        // Na 656 o ultNSU retornado é o NSU global corrente, não um ponto de retomada confiável.
        // Avançar aqui faria o sync pular todos os documentos anteriores a esse valor.
        if (! $ret->is(CStat::ConsumoIndevido)) {
            $this->advance($state, $ret);
        } elseif ($ret->maxNsu !== null) {
            $state->max_nsu = $ret->maxNsu;
        }

        $state->next_check_at = $this->waitOneHour($company, $state->tipo, $ret->is(CStat::ConsumoIndevido) ? 'wait_after_656_minutes' : 'wait_after_no_docs_minutes');

        if ($ret->is(CStat::ConsumoIndevido)) {
            $state->consecutive_656++;
            Log::warning('SEFAZ consumo indevido (656)', ['company_id' => $company->id, 'tipo' => $state->tipo->value, 'consecutivos' => $state->consecutive_656]);

            if ($state->consecutive_656 === config('sefaz.blocked_alert_after')) {
                Notification::send($company->organization->managers(), new CompanyBlocked($company, $state->consecutive_656));
            }
        } elseif ($ret->is(CStat::NenhumDocumento)) {
            $state->consecutive_656 = 0;
        } else {
            $state->last_error = Str::limit("{$ret->cStat} - {$ret->xMotivo}", 250);
        }

        $state->save();

        return match (true) {
            $ret->is(CStat::NenhumDocumento) => SyncOutcome::UpToDate,
            $ret->is(CStat::ConsumoIndevido) => SyncOutcome::Blocked,
            default => SyncOutcome::Failed,
        };
    }

    /** O ultNSU só avança: um valor menor (ou ausente) nunca sobrescreve o persistido. */
    private function advance(SyncState $state, RetDistDFe $ret): void
    {
        if ($ret->ultNsu !== null && $ret->ultNsu > $state->ult_nsu) {
            $state->ult_nsu = $ret->ultNsu;
        }

        if ($ret->maxNsu !== null) {
            $state->max_nsu = $ret->maxNsu;
        }
    }

    private function waitOneHour(Company $company, DocumentoTipo $tipo, string $configKey = 'wait_after_no_docs_minutes'): CarbonImmutable
    {
        $until = now()->addMinutes(config("sefaz.{$configKey}"));
        $this->cooldown->set($company, $until, $tipo);

        return $until;
    }

    private function failed(Company $company, SyncState $state, string $sent, Throwable $e, float $started): SyncOutcome
    {
        $message = FailureClassifier::safeMessage($e);

        $this->logger->logError($company, $state->tipo->servicoDistribuicao(), $started, $message, ultNsuEnviado: $sent);
        Log::error('Falha na distribuição DF-e', ['company_id' => $company->id, 'tipo' => $state->tipo->value, 'exception' => $e::class, 'code' => $e->getCode(), 'message' => $message]);

        $failures = $state->consecutive_failures + 1;

        // Backoff exponencial só quando a requisição certamente não chegou à SEFAZ; nos demais casos, a espera da NT.
        $next = FailureClassifier::isRetryableSoon($e)
            ? now()->addMinutes(min(config('sefaz.retry_base_minutes') * 2 ** ($failures - 1), config('sefaz.retry_max_minutes')))
            : $this->waitOneHour($company, $state->tipo);

        $state->update([
            'last_checked_at' => now(),
            'last_error' => $message,
            'consecutive_failures' => $failures,
            'next_check_at' => $next,
        ]);

        return SyncOutcome::Failed;
    }
}
