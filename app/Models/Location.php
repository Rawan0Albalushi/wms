<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'zone', 'rack', 'shelf', 'kind', 'image_path'])]
class Location extends Model
{
    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function recordedPositions(): HasMany
    {
        return $this->hasMany(RecordedPosition::class);
    }
}
