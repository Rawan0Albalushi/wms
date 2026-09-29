<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Shipment;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentFlow
{
    public function __construct(private StockLedger $ledger) {}

    public function request(array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data) {
            return PurchaseRequest::query()->create([
                'number' => Numbers::next('RFQ', PurchaseRequest::class),
                'item_id' => $data['item_id'],
                'quantity' => $data['quantity'],
                'status' => 'open',
                'note' => $data['note'] ?? null,
            ]);
        });
    }

    public function order(array $data): PurchaseOrder
    {
        $order = DB::transaction(function () use ($data) {
            $request = null;

            if (! empty($data['purchase_request_id'])) {
                $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($data['purchase_request_id']);

                if ($request->status !== 'open') {
                    throw ValidationException::withMessages(['purchase_request_id' => 'request_closed']);
                }
            }

            $order = PurchaseOrder::query()->create([
                'number' => Numbers::next('PO', PurchaseOrder::class),
                'supplier_id' => $data['supplier_id'],
                'purchase_request_id' => $request?->id,
                'status' => 'ordered',
                'ordered_on' => now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $order->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            if ($request) {
                $request->status = 'converted';
                $request->save();
            }

            return $order;
        });

        return $order;
    }

    public function shipment(array $data): Shipment
    {
        $shipment = Shipment::query()->create([
            'purchase_order_id' => $data['purchase_order_id'],
            'ci_number' => $data['ci_number'],
            'expected_on' => $data['expected_on'],
            'status' => 'in_transit',
            'packing_note' => $data['packing_note'] ?? null,
        ]);

        $this->ledger->deferAlerts([], true);

        return $shipment;
    }

    public function arrive(Shipment $shipment): void
    {
        if ($shipment->status === 'received') {
            throw ValidationException::withMessages(['shipment' => 'already_received']);
        }

        $shipment->status = 'arrived';
        $shipment->save();
        $this->ledger->deferAlerts([], true);
    }

    public function job(array $data): JobOrder
    {
        return DB::transaction(function () use ($data) {
            $job = JobOrder::query()->create([
                'number' => Numbers::next('JOB', JobOrder::class),
                'title' => $data['title'],
                'status' => 'open',
            ]);

            foreach ($data['lines'] as $line) {
                $job->lines()->create([
                    'item_id' => $line['item_id'],
                    'required_qty' => $line['quantity'],
                ]);
            }

            $this->ledger->deferAlerts(array_column($data['lines'], 'item_id'));

            return $job;
        });
    }
}
