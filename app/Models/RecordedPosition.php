<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'location_id', 'quantity', 'condition', 'reference', 'line_type', 'recorded_on', 'import_key'])]
class RecordedPosition extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'recorded_on' => 'date:Y-m-d',
        ];
    }
}
