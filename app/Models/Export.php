<?php

namespace App\Models;

use App\Enums\ExportFormato;
use App\Enums\ExportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property array<string, mixed> $filters
 * @property ExportFormato $formato
 * @property ExportStatus $status
 * @property int $total
 * @property string|null $path
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
#[Fillable(['organization_id', 'user_id', 'filters', 'formato', 'status', 'total', 'path', 'error'])]
class Export extends Model
{
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'formato' => ExportFormato::class,
            'status' => ExportStatus::class,
            'total' => 'integer',
        ];
    }

    protected $attributes = ['formato' => 'zip'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
