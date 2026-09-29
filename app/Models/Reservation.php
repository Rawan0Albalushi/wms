<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['job_line_id', 'balance_id', 'quantity'])]
class Reservation extends Model
{
    public function line(): BelongsTo
    {
        return $this->belongsTo(JobLine::class, 'job_line_id');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(Balance::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
