<?php

namespace App\Models;

use App\Casts\VaultEncrypted;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $company_id
 * @property int|null $organization_id
 * @property string $pfx Conteúdo binário do PKCS#12 (descriptografado pelo cast).
 * @property string $password
 * @property string $titular
 * @property string $cnpj_certificado CNPJ do e-CNPJ ou, para o e-CPF do contador, o CPF.
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_to
 * @property string $fingerprint
 * @property bool $active
 */
#[Fillable(['pfx', 'password', 'titular', 'cnpj_certificado', 'valid_from', 'valid_to', 'fingerprint', 'active'])]
#[Hidden(['pfx', 'password'])]
class Certificate extends Model
{
    protected function casts(): array
    {
        return [
            'pfx' => VaultEncrypted::class,
            'password' => VaultEncrypted::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Certificado do escritório contábil (da organização), e não de uma empresa. */
    public function isOffice(): bool
    {
        return $this->company_id === null;
    }

    public function documentoFormatado(): string
    {
        return strlen($this->cnpj_certificado) === 11
            ? (string) preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $this->cnpj_certificado)
            : Fmt::cnpj($this->cnpj_certificado);
    }

    public function isExpired(): bool
    {
        return $this->valid_to->isPast();
    }

    public function daysUntilExpiration(): int
    {
        return (int) floor(now()->diffInDays($this->valid_to, false));
    }
}
