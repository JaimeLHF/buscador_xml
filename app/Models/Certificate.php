<?php

namespace App\Models;

use App\Casts\VaultEncrypted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $pfx Conteúdo binário do PKCS#12 (descriptografado pelo cast).
 * @property string $password
 * @property string $titular
 * @property string $cnpj_certificado
 * @property Carbon $valid_from
 * @property Carbon $valid_to
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

    public function isExpired(): bool
    {
        return $this->valid_to->isPast();
    }

    public function daysUntilExpiration(): int
    {
        return (int) floor(now()->diffInDays($this->valid_to, false));
    }
}
