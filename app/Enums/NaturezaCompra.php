<?php

namespace App\Enums;

/** Natureza da entrada pelo CFOP (os três últimos dígitos; o primeiro só diz se é do estado, de fora ou do exterior). */
enum NaturezaCompra: string
{
    case Revenda = 'revenda';
    case Industrializacao = 'industrializacao';
    case UsoConsumo = 'uso_consumo';
    case Ativo = 'ativo';
    case Combustivel = 'combustivel';
    case Energia = 'energia';
    case Transferencia = 'transferencia';
    case Devolucao = 'devolucao';
    case Bonificacao = 'bonificacao';
    case Remessa = 'remessa';
    case Outras = 'outras';

    public static function doCfop(?string $cfop): ?self
    {
        if ($cfop === null || ! preg_match('/^[123]\d{3}$/', $cfop)) {
            return null;
        }

        $op = (int) substr($cfop, 1);

        return match (true) {
            in_array($op, [102, 113, 117, 118, 121, 403], true) => self::Revenda,
            in_array($op, [101, 111, 116, 120, 122, 401], true) => self::Industrializacao,
            in_array($op, [407, 556], true) => self::UsoConsumo,
            in_array($op, [406, 551], true) => self::Ativo,
            $op >= 651 && $op <= 663 => self::Combustivel,
            $op >= 251 && $op <= 257 => self::Energia,
            in_array($op, [151, 152, 153, 154, 155, 156, 408, 409, 552, 557], true) => self::Transferencia,
            ($op >= 201 && $op <= 209) || in_array($op, [410, 411, 553], true) => self::Devolucao,
            in_array($op, [910, 911], true) => self::Bonificacao,
            ($op >= 901 && $op <= 909) || in_array($op, [912, 913, 914, 915, 916, 917, 920, 921, 923, 924, 925, 934], true) => self::Remessa,
            default => self::Outras,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Revenda => __('Revenda'),
            self::Industrializacao => __('Industrialização'),
            self::UsoConsumo => __('Uso e consumo'),
            self::Ativo => __('Ativo imobilizado'),
            self::Combustivel => __('Combustível'),
            self::Energia => __('Energia elétrica'),
            self::Transferencia => __('Transferência'),
            self::Devolucao => __('Devolução'),
            self::Bonificacao => __('Bonificação ou amostra'),
            self::Remessa => __('Remessa ou retorno'),
            self::Outras => __('Outras entradas'),
        };
    }
}
