<?php

namespace App\Services\MeuDanfe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API v2 do Meu Danfe. PUT fd/add/{chave} inicia a busca (e cobra uma vez por chave) e é repetido até o status
 * sair de WAITING/SEARCHING; GET fd/get/xml/{chave} baixa o XML de uma chave com status OK.
 */
class MeuDanfeClient
{
    public static function configurado(): bool
    {
        return filled(config('services.meudanfe.key'));
    }

    /** @return string OK, NOT_FOUND, ERROR, WAITING ou SEARCHING */
    public function adicionar(string $chave): string
    {
        $response = $this->http()->put("fd/add/{$chave}");

        if ($response->status() === 402) {
            throw new SemSaldoException('Sem saldo para a consulta completa.');
        }

        $status = $response->throw()->json('status');

        if (! in_array($status, ['OK', 'NOT_FOUND', 'ERROR', 'WAITING', 'SEARCHING'], true)) {
            throw new RuntimeException('Resposta da consulta sem status reconhecível.');
        }

        return $status;
    }

    public function xml(string $chave): string
    {
        $payload = $this->http()->get("fd/get/xml/{$chave}")->throw()->json();
        $data = (string) ($payload['data'] ?? '');

        return str_starts_with(ltrim($data), '<') ? $data : (string) base64_decode($data, true);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.meudanfe.url'), '/').'/')
            ->withHeaders(['Api-Key' => config('services.meudanfe.key')])
            ->acceptJson()
            ->timeout(30);
    }
}
