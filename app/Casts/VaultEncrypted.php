<?php

namespace App\Casts;

use App\Services\Certificates\CertificateVault;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<string, string>
 */
class VaultEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(CertificateVault::class)->decrypt($value);
    }

    public function set(Model $model, string $key, #[\SensitiveParameter] mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(CertificateVault::class)->encrypt($value);
    }
}
