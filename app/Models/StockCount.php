<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'status', 'started_at', 'approved_at'])]
class StockCount extends Model
{
    protected $table = 'counts';

    public function lines(): HasMany
    {
        return $this->hasMany(CountLine::class, 'count_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
