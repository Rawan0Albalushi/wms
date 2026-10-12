<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'supplier_id', 'purchase_request_id', 'status', 'kind', 'ordered_on', 'notes'])]
class PurchaseOrder extends Model
{
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date:Y-m-d',
        ];
    }
}
