<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'part_number', 'barcode', 'image_path', 'name_ar', 'name_en', 'category_id', 'unit_id', 'supplier_id', 'min_level', 'unit_cost', 'stock_status', 'family', 'alt_unit'])]
class Item extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function recordedPositions(): HasMany
    {
        return $this->hasMany(RecordedPosition::class);
    }

    protected function casts(): array
    {
        return [
            'min_level' => 'integer',
            'unit_cost' => 'integer',
        ];
    }
}
