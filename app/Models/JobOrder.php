<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'title', 'status'])]
class JobOrder extends Model
{
    protected $table = 'job_orders';

    public function lines(): HasMany
    {
        return $this->hasMany(JobLine::class, 'job_id');
    }
}
