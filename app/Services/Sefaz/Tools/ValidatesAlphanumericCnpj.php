<?php

namespace App\Services\Sefaz\Tools;

use App\Support\Cnpj;

/**
 * O sped-nfe 5.2.8 e o sped-cte 5.0.1 validam as mensagens com XSDs antigos, em que o CNPJ só aceita dígitos
 * ([0-9]{14}), e recusam o CNPJ alfanumérico (IN RFB 2.229/2024) antes do envio. A SEFAZ já aceita o novo formato.
 *
 * A validação local continua acontecendo, mas sobre uma cópia da mensagem em que cada CNPJ alfanumérico válido
 * (DV conferido) vira um número fixo de 14 dígitos. A mensagem enviada e assinada não muda.
 * Remover quando a biblioteca trouxer os XSDs com o padrão [A-Z0-9]{12}[0-9]{2}.
 */
trait ValidatesAlphanumericCnpj
{
    protected function isValid($version, $body, $method): bool
    {
        $paraValidar = preg_replace_callback(
            '#<CNPJ>([0-9A-Z]{12}[0-9]{2})</CNPJ>#',
            fn (array $m) => ctype_digit($m[1]) || ! Cnpj::isValid($m[1]) ? $m[0] : '<CNPJ>00000000000000</CNPJ>',
            (string) $body,
        );

        return parent::isValid($version, $paraValidar, $method);
    }
}
