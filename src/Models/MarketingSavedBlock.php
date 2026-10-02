<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property string $name
 * @property string $category
 * @property string $slot_type
 * @property array<string, mixed> $slot_data
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $creator
 */
class MarketingSavedBlock extends Model
{
    protected $table = 'odden_marketing_saved_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'slot_type',
        'slot_data',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'slot_data' => 'array',
    ];

    /**
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::className(), 'created_by');
    }
}
