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

    /** "hoje às 09:23", "ontem às 18:00" ou "20/10 às 14:05": leitura rápida de quando algo aconteceu. */
    public static function relativo(string|DateTimeInterface|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $at = (is_string($value) ? CarbonImmutable::parse($value, 'UTC') : CarbonImmutable::instance($value))->timezone(self::TIMEZONE);
        $hoje = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        return match (true) {
            $at->greaterThanOrEqualTo($hoje) => 'hoje às '.$at->format('H:i'),
            $at->greaterThanOrEqualTo($hoje->subDay()) => 'ontem às '.$at->format('H:i'),
            default => $at->format('d/m').' às '.$at->format('H:i'),
        };
    }

    /** Data/hora do XML (ISO 8601, com ou sem fuso) no formato brasileiro; devolve o texto original se não for data. */
    public static function xmlDate(?string $value, bool $hora = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return CarbonImmutable::parse($value, self::TIMEZONE)->timezone(self::TIMEZONE)->format($hora && strlen($value) > 10 ? 'd/m/Y H:i' : 'd/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Número do XML com separadores brasileiros, sem zeros sobrando à direita (até $max casas). */
    public static function num(?string $value, int $max = 4, int $min = 0): string
    {
        if ($value === null || ! is_numeric($value)) {
            return (string) $value;
        }

        $txt = number_format((float) $value, $max, ',', '.');
        $txt = rtrim(rtrim($txt, '0'), ',');
        [$int, $dec] = array_pad(explode(',', $txt), 2, '');

        return $int.(strlen($dec) < $min ? ','.str_pad($dec, $min, '0') : ($dec !== '' ? ','.$dec : ''));
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
