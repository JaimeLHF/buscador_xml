<?php

namespace App\Models;

use App\Enums\Ambiente;
use App\Enums\DocumentoTipo;
use App\Enums\ModoBusca;
use App\Enums\Uf;
use App\Support\Cnpj;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $cnpj
 * @property string $razao_social
 * @property string|null $nome_fantasia
 * @property Uf $uf
 * @property Ambiente $ambiente
 * @property bool $ativo
 * @property bool $manifestacao_automatica
 * @property bool $buscar_cte
 * @property ModoBusca $modo_busca
 */
#[Fillable(['cnpj', 'razao_social', 'nome_fantasia', 'uf', 'ambiente', 'ativo', 'manifestacao_automatica', 'buscar_cte', 'modo_busca'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            $company->cnpj = Cnpj::normalize($company->cnpj);
        });

        static::created(function (Company $company): void {
            foreach (DocumentoTipo::cases() as $tipo) {
                $company->syncStates()->create(['tipo' => $tipo]);
            }
        });
    }

    protected $attributes = ['modo_busca' => 'certificado_empresa'];

    protected function casts(): array
    {
        return [
            'uf' => Uf::class,
            'ambiente' => Ambiente::class,
            'ativo' => 'boolean',
            'manifestacao_automatica' => 'boolean',
            'buscar_cte' => 'boolean',
            'modo_busca' => ModoBusca::class,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Certificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** @return HasOne<Certificate, $this> */
    public function activeCertificate(): HasOne
    {
        return $this->hasOne(Certificate::class)->where('active', true)->latestOfMany();
    }

    /** @return HasMany<SyncState, $this> */
    public function syncStates(): HasMany
    {
        return $this->hasMany(SyncState::class);
    }

    /** @return HasMany<DfeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(DfeDocument::class);
    }

    /** @return HasMany<DfeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DfeEvent::class);
    }

    /** @return HasMany<SefazRequest, $this> */
    public function sefazRequests(): HasMany
    {
        return $this->hasMany(SefazRequest::class);
    }

    /** @return HasOne<SefScSyncState, $this> */
    public function sefScSyncState(): HasOne
    {
        return $this->hasOne(SefScSyncState::class);
    }

    public function sefScState(): SefScSyncState
    {
        return $this->sefScSyncState()->firstOrCreate();
    }

    public function buscaPeloContador(): bool
    {
        return $this->modo_busca === ModoBusca::ContadorSc;
    }

    /** Certificado usado para buscar as notas: o da empresa ou, no modo contador, o do escritório. */
    public function certificadoDeBusca(): ?Certificate
    {
        return $this->buscaPeloContador() ? $this->organization->officeCertificate : $this->activeCertificate;
    }

    /** NF-e é sempre buscada; CT-e só quando a empresa liga a opção. */
    public function buscaTipo(DocumentoTipo $tipo): bool
    {
        return $tipo === DocumentoTipo::Nfe || $this->buscar_cte;
    }

    public function syncState(DocumentoTipo $tipo = DocumentoTipo::Nfe): SyncState
    {
        return $this->syncStates()->firstOrCreate(['tipo' => $tipo]);
    }

    public function cnpjValue(): Cnpj
    {
        return Cnpj::from($this->cnpj);
    }
}
