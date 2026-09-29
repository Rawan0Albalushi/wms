<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['item_id', 'supplier_id', 'batch_no', 'received_on', 'condition', 'reference', 'expires_on'])]
class Batch extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
        ];
    }
}
