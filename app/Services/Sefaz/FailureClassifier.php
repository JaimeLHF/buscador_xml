<?php

namespace App\Services\Sefaz;

use NFePHP\Common\Exception\SoapException;
use Throwable;

/**
 * Decide se uma falha pode ter chegado à SEFAZ.
 *
 * Só falhas em que a requisição comprovadamente não foi entregue (DNS, conexão recusada, handshake TLS,
 * certificado rejeitado no TLS) permitem nova tentativa antes de 1 hora. Timeout, resposta vazia ou erro 5xx
 * são ambíguos: a SEFAZ pode ter processado e respondido 137, e consultar de novo antes de 1 hora geraria 656.
 */
final class FailureClassifier
{
    /** Códigos cURL emitidos antes de qualquer byte da requisição SOAP ser enviado. */
    private const UNDELIVERED_CURL_CODES = [5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 77, 83];

    /** HTTP 403: o IIS da SEFAZ recusou o certificado cliente antes de processar a mensagem. */
    private const UNDELIVERED_HTTP_CODES = [403];

    public static function isRetryableSoon(Throwable $e): bool
    {
        return $e instanceof SoapException
            && in_array((int) $e->getCode(), [...self::UNDELIVERED_CURL_CODES, ...self::UNDELIVERED_HTTP_CODES], true);
    }

    /** Remove caminhos de arquivos temporários (onde o sped grava a chave do certificado) e limita o tamanho. */
    public static function safeMessage(Throwable|string $e): string
    {
        $message = $e instanceof Throwable ? $e->getMessage() : $e;

        return mb_strimwidth((string) preg_replace('#/[^\s\]]*(sped|tmp)[^\s\]]*#i', '[path]', $message), 0, 250, '…');
    }
}
