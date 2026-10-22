<?php

namespace App\Services\Sefaz;

use Illuminate\Support\Facades\Cache;

/**
 * Status em tempo real da sincronização de uma empresa, persistido no Redis para o frontend ler via polling.
 *
 * Ciclo de vida: queued → running → (páginas…) → done/error.
 * TTL de 15 min: se o job morrer sem limpar, a flag expira sozinha.
 */
class SyncProgress
{
    private const TTL = 900;

    public static function key(int $companyId): string
    {
        return "sync-progress:{$companyId}";
    }

    public static function queued(int $companyId, string $label): void
    {
        Cache::put(self::key($companyId), [
            'status' => 'queued',
            'message' => $label,
            'page' => 0,
            'docs' => 0,
            'ult_nsu' => null,
            'max_nsu' => null,
            'started_at' => now()->toIso8601String(),
        ], self::TTL);
    }

    public static function running(int $companyId, int $page, int $docs, ?string $ultNsu, ?string $maxNsu): void
    {
        $prev = Cache::get(self::key($companyId), []);

        Cache::put(self::key($companyId), array_merge($prev, [
            'status' => 'running',
            'page' => $page,
            'docs' => ($prev['docs'] ?? 0) + $docs,
            'ult_nsu' => $ultNsu,
            'max_nsu' => $maxNsu,
        ]), self::TTL);
    }

    public static function done(int $companyId, SyncOutcome $outcome, ?string $message = null): void
    {
        $prev = Cache::get(self::key($companyId), []);

        Cache::put(self::key($companyId), array_merge($prev, [
            'status' => 'done',
            'outcome' => $outcome->value,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]), 120); // Mantém 2 min para o frontend mostrar o resultado final.
    }

    public static function get(int $companyId): ?array
    {
        return Cache::get(self::key($companyId));
    }

    public static function forget(int $companyId): void
    {
        Cache::forget(self::key($companyId));
    }
}
