<?php

namespace App\Services\Sefaz;

use App\Models\Company;
use Illuminate\Support\Facades\Redis;

/**
 * Janela deslizante de 1 hora por CNPJ/ambiente para consChNFe e consNSU (limite da NT: 20/hora).
 * A reserva é atômica (Lua), então workers concorrentes nunca passam do limite.
 */
class ConsultaLimiter
{
    private const SCRIPT = <<<'LUA'
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1] - 3600000)
        if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) then
            return 0
        end
        redis.call('ZADD', KEYS[1], ARGV[1], ARGV[3])
        redis.call('PEXPIRE', KEYS[1], 3600000)
        return 1
        LUA;

    public function attempt(Company $company): bool
    {
        $now = (int) (microtime(true) * 1000);

        $args = [$this->key($company), $now, $this->limit(), $now.':'.bin2hex(random_bytes(4))];

        // Assinatura nativa do phpredis: eval(script, [chaves..., argumentos...], quantidade de chaves).
        return (bool) Redis::connection()->command('eval', [self::SCRIPT, $args, 1]);
    }

    public function remaining(Company $company): int
    {
        $key = $this->key($company);
        $redis = Redis::connection();
        $redis->zremrangebyscore($key, '-inf', (string) ((int) (microtime(true) * 1000) - 3_600_000));

        return max(0, $this->limit() - (int) $redis->zcard($key));
    }

    private function limit(): int
    {
        return (int) config('sefaz.cons_chave_per_hour');
    }

    private function key(Company $company): string
    {
        return "sefaz:cons:{$company->ambiente->value}:{$company->cnpj}";
    }
}
