<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Formatação para exibição (fuso de Brasília, moeda e CNPJ), mantendo o banco em UTC. */
final class Fmt
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public static function dateTime(?DateTimeInterface $value): string
    {
        return $value ? CarbonImmutable::instance($value)->timezone(self::TIMEZONE)->format('d/m/Y H:i') : '—';
    }

    public static function date(?DateTimeInterface $value): string
    {
        return $value ? CarbonImmutable::instance($value)->timezone(self::TIMEZONE)->format('d/m/Y') : '—';
    }

    public static function money(string|float|null $value): string
    {
        return $value === null ? '—' : 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    public static function cnpj(?string $value): string
    {
        return $value && Cnpj::isValid($value) ? Cnpj::from($value)->formatted() : (string) $value;
    }
}
