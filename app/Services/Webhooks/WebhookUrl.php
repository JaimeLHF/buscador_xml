<?php

namespace App\Services\Webhooks;

use InvalidArgumentException;

/**
 * Valida o endereço do webhook contra SSRF: só HTTPS (HTTP apenas em ambiente local) e só IPs públicos.
 * A resolução de DNS é feita aqui e o IP é fixado na conexão, para o host não trocar de IP entre a checagem e o envio.
 */
final readonly class WebhookUrl
{
    private function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    public static function resolve(string $url): self
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, app()->isLocal() ? ['https', 'http'] : ['https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Use um endereço https:// válido.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new InvalidArgumentException('Não foi possível encontrar o servidor desse endereço.');
        }

        foreach ($ips as $ip) {
            if (! app()->isLocal() && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('O endereço aponta para uma rede interna, o que não é permitido.');
            }
        }

        return new self($url, $host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $ips[0]);
    }

    /** Opção do cURL que faz a conexão usar o IP já validado. */
    public function curlResolve(): string
    {
        return "{$this->host}:{$this->port}:{$this->ip}";
    }
}
