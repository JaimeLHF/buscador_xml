<?php

namespace App\Services\Sefaz;

use App\Enums\CStat;
use App\Enums\DocumentoTipo;
use App\Enums\SefazServico;
use App\Models\Company;
use App\Models\SyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NFePHP\Common\Exception\SoapException;
use Throwable;

/**
 * Máquina de estados do NSU (NT 2014.002):
 *  138 → processa e segue enquanto ultNSU < maxNSU; ao alcançar maxNSU, espera 1 hora.
 *  137 → nada novo: espera 1 hora.
 *  656 → consumo indevido: para e espera 1 hora (usando o ultNSU retornado, se houver).
 *  Qualquer outro retorno também espera 1 hora, para nunca provocar um 656.
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

    public function sync(Company $company): SyncOutcome
    {
        // Um único processo por CNPJ/ambiente por vez (o limite da SEFAZ é por CNPJ, não por cadastro).
        $lock = Cache::lock("sefaz-dist:{$company->ambiente->value}:{$company->cnpj}", 660);

        if (! $lock->get()) {
            return SyncOutcome::Locked;
        }

        try {
            return $this->run($company);
        } finally {
            $lock->release();
        }
    }

    private function run(Company $company): SyncOutcome
    {
        $state = $company->syncState(DocumentoTipo::Nfe);

        if ($state->next_check_at?->isFuture()) {
            return SyncOutcome::NotDue;
        }

        if ($until = $this->cooldown->until($company)) {
            $state->update(['next_check_at' => $until]);

            return SyncOutcome::NotDue;
        }

        for ($page = 0; $page < config('sefaz.max_pages_per_run'); $page++) {
            $outcome = $this->fetchPage($company, $state);

            if ($outcome !== SyncOutcome::MorePending) {
                return $outcome;
            }
        }

        // Ainda há documentos: o scheduler retoma no próximo minuto, liberando o worker para outras empresas.
        $state->update(['next_check_at' => now()]);

        return SyncOutcome::MorePending;
    }

    private function fetchPage(Company $company, SyncState $state): SyncOutcome
    {
        $sent = $state->ult_nsu;
        $started = microtime(true);

        try {
            $ret = $this->parser->parse($this->gateway->distNsu($company, $sent));
        } catch (Throwable $e) {
            return $this->failed($company, $state, $sent, $e, $started);
        }

        $this->logger->log($company, SefazServico::DistNsu, $started, $ret, ultNsuEnviado: $sent);

        $state->fill([
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
                    $state->next_check_at = $this->waitOneHour($company);
                }

                $state->save();
            });

            return $ret->hasMore() ? SyncOutcome::MorePending : SyncOutcome::UpToDate;
        }

        $this->advance($state, $ret);
        $state->next_check_at = $this->waitOneHour($company, $ret->is(CStat::ConsumoIndevido) ? 'wait_after_656_minutes' : 'wait_after_no_docs_minutes');

        if ($ret->is(CStat::ConsumoIndevido)) {
            $state->consecutive_656++;
            Log::warning('SEFAZ consumo indevido (656)', ['company_id' => $company->id, 'consecutivos' => $state->consecutive_656]);
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

    private function waitOneHour(Company $company, string $configKey = 'wait_after_no_docs_minutes'): CarbonImmutable
    {
        $until = now()->addMinutes(config("sefaz.{$configKey}"));
        $this->cooldown->set($company, $until);

        return $until;
    }

    private function failed(Company $company, SyncState $state, string $sent, Throwable $e, float $started): SyncOutcome
    {
        $message = Str::limit($this->safeMessage($e), 250);

        $this->logger->logError($company, SefazServico::DistNsu, $started, $message, ultNsuEnviado: $sent);
        Log::error('Falha na distribuição DF-e', ['company_id' => $company->id, 'exception' => $e::class, 'message' => $message]);

        // Falha de comunicação tende a ser transitória; erro de dados/configuração (ex.: XML inválido) não se resolve sozinho.
        $wait = $e instanceof SoapException ? config('sefaz.wait_after_network_error_minutes') : config('sefaz.wait_after_no_docs_minutes');

        $state->update([
            'last_checked_at' => now(),
            'last_error' => $message,
            'next_check_at' => now()->addMinutes($wait),
        ]);

        return SyncOutcome::Failed;
    }

    /** Mensagens de exceção do cURL/sped podem trazer caminhos de arquivos temporários de chave; ficam só o essencial. */
    private function safeMessage(Throwable $e): string
    {
        return (string) preg_replace('#/[^\s\]]*(sped|tmp)[^\s\]]*#i', '[path]', $e->getMessage());
    }
}
