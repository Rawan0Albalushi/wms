<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['job_id', 'item_id', 'required_qty', 'reserved_qty', 'issued_qty'])]
class JobLine extends Model
{
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class, 'job_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected function casts(): array
    {
        return [
            'required_qty' => 'integer',
            'reserved_qty' => 'integer',
            'issued_qty' => 'integer',
        ];
    }
}
