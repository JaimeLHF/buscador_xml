<?php

namespace App\Services\SefSc;

use App\Enums\Ambiente;
use App\Enums\SefazServico;
use App\Enums\Uf;
use App\Models\Company;
use App\Models\SefScSyncState;
use App\Services\Sefaz\DocumentProcessor;
use App\Services\Sefaz\FailureClassifier;
use App\Services\Sefaz\SefazRequestLogger;
use App\Services\Sefaz\SyncOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Busca pela SEF-SC com o certificado do escritório (BT SC-2026/003):
 *  118 com 50 documentos → processa e pede a continuação na hora;
 *  118 com menos de 50, ou 117 → sincronizado: espera 12 horas (antes disso a SEF-SC bloqueia com 657);
 *  110 → reprocessamento: tenta em 1 hora; qualquer rejeição → 12 horas, para nunca repetir o mesmo pedido.
 */
class SefScDistribuicaoService
{
    /** Rejeições com causa que o usuário resolve fora do sistema: a mensagem na tela diz o que fazer. */
    private const ORIENTACOES = [
        '8002' => 'O titular do certificado do escritório não está cadastrado na SEF-SC como contador desta empresa. Peça à empresa para indicar o contador no cadastro da SEF-SC.',
        '657' => 'A SEF-SC bloqueou por excesso de tentativas. O sistema espera 12 horas antes de tentar de novo.',
        '489' => 'CNPJ não aceito pela SEF-SC. Confira o CNPJ da empresa.',
    ];

    public function __construct(
        private SefScGateway $gateway,
        private DocumentProcessor $processor,
        private SefazRequestLogger $logger,
    ) {}

    public function sync(Company $company): SyncOutcome
    {
        $lock = Cache::lock("sef-sc:{$company->cnpj}", 660);

        if (! $lock->get()) {
            return SyncOutcome::Locked;
        }

        try {
            return $this->run($company);
        } finally {
            $lock->release();
        }
    }

    /** Motivo pelo qual a empresa ainda não pode buscar pela SEF-SC, em linguagem simples; null se pode. */
    public static function impedimento(Company $company): ?string
    {
        $certificado = $company->organization->officeCertificate;

        return match (true) {
            ! $company->buscaPeloContador() => 'A empresa busca notas com o próprio certificado.',
            $company->uf !== Uf::SC => 'Este modo só vale para empresas de Santa Catarina.',
            $company->ambiente !== Ambiente::Producao => 'O serviço da SEF-SC só existe em produção. Mude o ambiente da empresa para Produção.',
            ! config('sefaz.allow_production') => 'Consultas em produção estão desabilitadas neste servidor (SEFAZ_ALLOW_PRODUCTION).',
            $certificado === null => 'Envie o certificado do escritório na tela de Empresas.',
            $certificado->isExpired() => 'O certificado do escritório venceu. Envie um novo na tela de Empresas.',
            default => null,
        };
    }

    private function run(Company $company): SyncOutcome
    {
        $state = $company->sefScState();

        if ($state->next_check_at?->isFuture()) {
            return SyncOutcome::NotDue;
        }

        if (self::impedimento($company) !== null) {
            return SyncOutcome::NotDue;
        }

        for ($page = 0; $page < config('sefaz.max_pages_per_run'); $page++) {
            $outcome = $this->fetchPage($company, $state);

            if ($outcome !== SyncOutcome::MorePending) {
                return $outcome;
            }
        }

        $state->update(['next_check_at' => now()]);

        return SyncOutcome::MorePending;
    }

    private function fetchPage(Company $company, SefScSyncState $state): SyncOutcome
    {
        $sent = $state->ult_nsu;
        $started = microtime(true);

        try {
            $ret = RetDistNFeSc::parse($this->gateway->distribuicao($company->organization->officeCertificate, $company, $sent));
        } catch (Throwable $e) {
            return $this->failed($company, $state, $sent, $e, $started);
        }

        $company->sefazRequests()->create([
            'servico' => SefazServico::DownloadSefSc,
            'tp_amb' => $company->ambiente->value,
            'cstat' => $ret->cStat,
            'xmotivo' => Str::limit($ret->xMotivo, 250),
            'ult_nsu_enviado' => $sent,
            'ult_nsu_retornado' => $ret->ultNsu,
            'documentos' => count($ret->documents),
            'duracao_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        $state->fill([
            'consecutive_failures' => 0,
            'last_checked_at' => now(),
            'last_cstat' => $ret->cStat,
            'last_xmotivo' => Str::limit($ret->xMotivo, 250),
            'last_error' => null,
        ]);

        if ($ret->documentosLocalizados()) {
            // Documentos e avanço do NSU na mesma transação, como na distribuição do Ambiente Nacional.
            DB::transaction(function () use ($company, $state, $ret): void {
                foreach ($ret->documents as $doc) {
                    $this->processor->process($company, $doc);
                }

                $this->advance($state, $ret->ultNsu ?? collect($ret->documents)->max(fn ($d) => (int) $d->nsu));

                if (! $ret->temMais()) {
                    $state->next_check_at = now()->addMinutes(config('sef_sc.espera_sincronizado_minutos'));
                }

                $state->save();
            });

            return $ret->temMais() ? SyncOutcome::MorePending : SyncOutcome::UpToDate;
        }

        $state->next_check_at = now()->addMinutes($ret->cStat === '110' ? config('sef_sc.espera_reprocessamento_minutos') : config('sef_sc.espera_sincronizado_minutos'));

        if ($ret->cStat !== '117') {
            $state->last_error = Str::limit(self::ORIENTACOES[$ret->cStat] ?? "{$ret->cStat} - {$ret->xMotivo}", 250);
            Log::warning('SEF-SC rejeitou a busca', ['company_id' => $company->id, 'cstat' => $ret->cStat]);
        }

        $state->save();

        return match ($ret->cStat) {
            '117' => SyncOutcome::UpToDate,
            '657' => SyncOutcome::Blocked,
            default => SyncOutcome::Failed,
        };
    }

    /** O NSU só avança, como no Ambiente Nacional. Comparação numérica: o NSU da SEF-SC não tem zeros à esquerda. */
    private function advance(SefScSyncState $state, string|int|null $nsu): void
    {
        if ($nsu !== null && (int) $nsu > (int) $state->ult_nsu) {
            $state->ult_nsu = (string) (int) $nsu;
        }
    }

    private function failed(Company $company, SefScSyncState $state, string $sent, Throwable $e, float $started): SyncOutcome
    {
        $message = FailureClassifier::safeMessage($e);
        $this->logger->logError($company, SefazServico::DownloadSefSc, $started, $message, ultNsuEnviado: $sent);
        Log::error('Falha na busca pela SEF-SC', ['company_id' => $company->id, 'exception' => $e::class, 'message' => $message]);

        $failures = $state->consecutive_failures + 1;

        // Falha que não chegou à SEF-SC: backoff curto. Ambígua: 1 hora (pode ter contado como tentativa).
        $next = FailureClassifier::isRetryableSoon($e)
            ? now()->addMinutes(min(config('sefaz.retry_base_minutes') * 2 ** ($failures - 1), config('sefaz.retry_max_minutes')))
            : now()->addMinutes(config('sef_sc.espera_reprocessamento_minutos'));

        $state->update([
            'last_checked_at' => now(),
            'last_error' => $message,
            'consecutive_failures' => $failures,
            'next_check_at' => $next,
        ]);

        return SyncOutcome::Failed;
    }
}
