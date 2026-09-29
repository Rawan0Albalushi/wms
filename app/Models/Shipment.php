<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_order_id', 'ci_number', 'expected_on', 'status', 'packing_note'])]
class Shipment extends Model
{
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    protected function casts(): array
    {
        return [
            'expected_on' => 'date:Y-m-d',
        ];
    }
}
