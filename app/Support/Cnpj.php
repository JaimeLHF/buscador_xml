<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * CNPJ numérico ou alfanumérico (IN RFB 2.229/2024): 12 posições [0-9A-Z] + 2 dígitos verificadores numéricos.
 * O DV usa o valor ASCII - 48 de cada caractere, então para CNPJs só numéricos o cálculo é o tradicional.
 */
final readonly class Cnpj implements Stringable
{
    private const FIRST_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const SECOND_WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(public string $value) {}

    public static function from(string $input): self
    {
        $value = self::normalize($input);

        if (! self::isValid($value)) {
            throw new InvalidArgumentException('CNPJ inválido.');
        }

        return new self($value);
    }

    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[.\/\-\s]/', '', $input));
    }

    public static function isValid(string $input): bool
    {
        $value = self::normalize($input);

        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $value) || preg_match('/^(\d)\1{13}$/', $value)) {
            return false;
        }

        $first = self::checkDigit(substr($value, 0, 12), self::FIRST_WEIGHTS);
        $second = self::checkDigit(substr($value, 0, 12).$first, self::SECOND_WEIGHTS);

        return substr($value, 12, 2) === $first.$second;
    }

    /** Completa uma base de 12 posições com os dígitos verificadores. */
    public static function fromBase(string $base): self
    {
        $base = self::normalize($base);
        $first = self::checkDigit($base, self::FIRST_WEIGHTS);

        return self::from($base.$first.self::checkDigit($base.$first, self::SECOND_WEIGHTS));
    }

    /**
     * @param  list<int>  $weights
     */
    private static function checkDigit(string $base, array $weights): string
    {
        $sum = 0;

        foreach (str_split($base) as $i => $char) {
            $sum += (ord($char) - 48) * $weights[$i];
        }

        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }

    /** Raiz (8 primeiras posições), que identifica a empresa independentemente da filial. */
    public function root(): string
    {
        return substr($this->value, 0, 8);
    }

    public function formatted(): string
    {
        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($this->value, 0, 2),
            substr($this->value, 2, 3),
            substr($this->value, 5, 3),
            substr($this->value, 8, 4),
            substr($this->value, 12, 2),
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
