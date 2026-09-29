<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Balance;
use App\Models\Batch;
use App\Models\CountLine;
use App\Models\Item;
use App\Models\JobLine;
use App\Models\JobOrder;
use App\Models\Movement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    /**
     * @return array{batch: Batch, balance: Balance}
     */
    public function receive(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $batch = Batch::query()->where('batch_no', $data['batch_no'])->lockForUpdate()->first();

            if ($batch && (int) $batch->item_id !== (int) $data['item_id']) {
                $this->fail('batch_no', 'batch_item');
            }

            if (! $batch) {
                $batch = Batch::query()->create([
                    'item_id' => $data['item_id'],
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'batch_no' => $data['batch_no'],
                    'received_on' => $data['received_on'] ?? now()->toDateString(),
                    'condition' => $data['condition'],
                    'reference' => $data['reference'] ?? null,
                    'expires_on' => $data['expires_on'] ?? null,
                ]);
                $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            }

            $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
            $qty = (int) $data['quantity'];
            $balance = $this->addQuantity($batch, $locationId ?: null, $qty);

            $this->move('in', $batch, $qty, (int) $data['employee_id'], [
                'to_location_id' => $balance->location_id,
                'reason' => 'receipt',
                'reference' => $data['reference'] ?? null,
            ]);

            $orderId = isset($data['purchase_order_id']) ? (int) $data['purchase_order_id'] : null;
            $shipment = null;

            if (! empty($data['shipment_id'])) {
                $shipment = Shipment::query()->lockForUpdate()->find($data['shipment_id']);

                if (! $shipment) {
                    $this->fail('shipment_id', 'line_missing');
                }

                if ($shipment->status === 'received') {
                    $this->fail('shipment_id', 'already_received');
                }

                if ($orderId && $orderId !== (int) $shipment->purchase_order_id) {
                    $this->fail('shipment_id', 'shipment_order');
                }

                $orderId = (int) $shipment->purchase_order_id;
            }

            $order = $orderId
                ? $this->applyReceiptToOrder($orderId, (int) $batch->item_id, $qty)
                : null;

            if ($shipment) {
                $shipment->status = $order?->status === 'received' ? 'received' : 'arrived';
                $shipment->save();
            }

            $this->deferAlerts([(int) $batch->item_id], $shipment !== null);

            return ['batch' => $batch, 'balance' => $balance];
        });
    }

    public function issue(array $data): void
    {
        DB::transaction(function () use ($data) {
            $balance = $this->lockPosition((int) $data['position_id']);
            $qty = (int) $data['quantity'];
            $itemId = (int) $balance->batch->item_id;
            $this->guardAvailable($balance, $qty);

            $from = $balance->location_id;
            $balance->quantity -= $qty;
            $this->keep($balance);

            $this->move('out', $balance->batch, $qty, (int) $data['employee_id'], [
                'from_location_id' => $from,
                'reason' => $data['reason'],
                'department' => $data['department'] ?? null,
                'reference' => $data['reference'] ?? null,
            ]);

            $this->deferAlerts([$itemId]);
        });
    }

    public function transfer(array $data): void
    {
        DB::transaction(function () use ($data) {
            $source = $this->lockPosition((int) $data['position_id']);
            $qty = (int) $data['quantity'];
            $to = (int) $data['to_location_id'];
            $itemId = (int) $source->batch->item_id;

            if ((int) $source->location_id === $to) {
                $this->fail('to_location_id', 'same_location');
            }

            $this->guardAvailable($source, $qty);
            $from = $source->location_id;
            $batch = $source->batch;
            $source->quantity -= $qty;
            $this->keep($source);
            $this->addQuantity($batch, $to, $qty);

            $this->move('transfer', $batch, $qty, (int) $data['employee_id'], [
                'from_location_id' => $from,
                'to_location_id' => $to,
                'reason' => 'transfer',
            ]);

            $this->deferAlerts([$itemId]);
        });
    }

    public function reserveJob(JobOrder $job): void
    {
        DB::transaction(function () use ($job) {
            if (! in_array($job->status, ['open', 'reserved', 'partial_hold', 'in_progress'], true)) {
                $this->fail('job', 'closed');
            }

            foreach ($job->lines()->lockForUpdate()->get() as $line) {
                $need = $line->required_qty - $line->reserved_qty - $line->issued_qty;

                if ($need <= 0) {
                    continue;
                }

                $balances = Balance::query()
                    ->select('balances.*')
                    ->join('batches', 'batches.id', '=', 'balances.batch_id')
                    ->where('batches.item_id', $line->item_id)
                    ->whereRaw('balances.quantity > balances.reserved')
                    ->orderBy('batches.received_on')
                    ->orderBy('balances.id')
                    ->lockForUpdate()
                    ->get();

                foreach ($balances as $balance) {
                    $free = $balance->quantity - $balance->reserved;
                    $take = min($free, $need);

                    if ($take <= 0) {
                        continue;
                    }

                    $balance->reserved += $take;
                    $balance->save();
                    $line->reserved_qty += $take;
                    Reservation::query()->create([
                        'job_line_id' => $line->id,
                        'balance_id' => $balance->id,
                        'quantity' => $take,
                    ]);
                    $need -= $take;

                    if ($need === 0) {
                        break;
                    }
                }

                $line->save();
            }

            $short = false;
            $held = false;

            foreach ($job->lines()->get() as $line) {
                if ($line->reserved_qty + $line->issued_qty < $line->required_qty) {
                    $short = true;
                }

                if ($line->reserved_qty > 0) {
                    $held = true;
                }
            }

            if ($job->status !== 'in_progress') {
                $job->status = ! $short ? 'reserved' : ($held ? 'partial_hold' : 'open');
            }

            $job->save();
            $this->deferAlerts($job->lines()->pluck('item_id')->all());
        });
    }

    public function issueJob(JobOrder $job, int $employeeId): void
    {
        DB::transaction(function () use ($job, $employeeId) {
            $moved = false;

            foreach ($job->lines()->lockForUpdate()->get() as $line) {
                $holds = Reservation::query()
                    ->where('job_line_id', $line->id)
                    ->where('quantity', '>', 0)
                    ->lockForUpdate()
                    ->get();

                foreach ($holds as $hold) {
                    $balance = $this->lockPosition($hold->balance_id);
                    $qty = (int) $hold->quantity;

                    if ($balance->quantity < $qty || $balance->reserved < $qty) {
                        $this->fail('quantity', 'insufficient');
                    }

                    $from = $balance->location_id;
                    $balance->quantity -= $qty;
                    $balance->reserved -= $qty;
                    $this->keep($balance);
                    $line->reserved_qty -= $qty;
                    $line->issued_qty += $qty;
                    $hold->delete();

                    $this->move('consume', $balance->batch, $qty, $employeeId, [
                        'from_location_id' => $from,
                        'reason' => 'job_order',
                        'reference' => $job->number,
                    ]);
                    $moved = true;
                }

                $line->save();
            }

            if (! $moved) {
                $this->fail('job', 'nothing_reserved');
            }

            $open = $job->lines()->whereColumn('issued_qty', '<', 'required_qty')->exists();
            $job->status = $open ? 'in_progress' : 'closed';
            $job->save();
            $this->deferAlerts($job->lines()->pluck('item_id')->all());
        });
    }

    public function openCount(): void
    {
        DB::transaction(function () {
            if (StockCount::query()->where('status', 'open')->exists()) {
                $this->fail('count', 'count_open');
            }

            $count = StockCount::query()->create([
                'number' => Numbers::next('CNT', StockCount::class),
                'status' => 'open',
                'started_at' => now(),
            ]);

            Balance::query()->with('batch')->where('quantity', '>', 0)->orderBy('id')->each(function (Balance $balance) use ($count) {
                CountLine::query()->create([
                    'count_id' => $count->id,
                    'item_id' => $balance->batch->item_id,
                    'location_id' => $balance->location_id,
                    'batch_id' => $balance->batch_id,
                    'system_qty' => $balance->quantity,
                ]);
            });
        });
    }

    /**
     * @param  array<int, array{id: int, counted_qty: int}>  $rows
     */
    public function recordLines(StockCount $count, array $rows): void
    {
        DB::transaction(function () use ($count, $rows) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($count->status !== 'open') {
                $this->fail('count', 'count_closed');
            }

            $lines = $count->lines()->lockForUpdate()->get()->keyBy('id');
            $touched = [];

            foreach ($rows as $row) {
                $line = $lines->get($row['id']);

                if (! $line || (int) $line->count_id !== (int) $count->id) {
                    $this->fail('lines', 'line_missing');
                }

                if ($row['counted_qty'] === null) {
                    $line->counted_qty = null;
                    $line->variance = null;
                } else {
                    $line->counted_qty = (int) $row['counted_qty'];
                    $line->variance = $line->counted_qty - $line->system_qty;
                }

                $line->save();
                $touched[] = (int) $line->item_id;
            }

            $this->deferAlerts($touched);
        });
    }

    public function approveCount(StockCount $count, int $employeeId): void
    {
        DB::transaction(function () use ($count, $employeeId) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($count->status !== 'open') {
                $this->fail('count', 'count_closed');
            }

            $lines = $count->lines()->lockForUpdate()->get();

            if ($lines->contains(fn (CountLine $line) => $line->counted_qty === null)) {
                $this->fail('count', 'count_incomplete');
            }

            foreach ($lines as $line) {
                if (! $line->batch_id) {
                    continue;
                }

                Batch::query()->lockForUpdate()->find($line->batch_id);
                $balance = $this->findLocked((int) $line->batch_id, $line->location_id);

                if (! $balance) {
                    $this->fail('lines', 'line_missing');
                }

                if ($line->counted_qty < $balance->reserved) {
                    $this->fail('counted_qty', 'below_reserved');
                }

                $delta = $line->counted_qty - $balance->quantity;

                if ($delta !== 0) {
                    $this->move('adjustment', $balance->batch, abs($delta), $employeeId, [
                        'from_location_id' => $delta < 0 ? $balance->location_id : null,
                        'to_location_id' => $delta > 0 ? $balance->location_id : null,
                        'reason' => 'count',
                        'reference' => $count->number,
                    ]);
                    $balance->quantity = $line->counted_qty;
                    $this->keep($balance);
                }
            }

            $count->status = 'approved';
            $count->approved_at = now();
            $count->save();
            $this->deferAlerts($lines->pluck('item_id')->all());
        });
    }

    /**
     * @param  list<int>  $itemIds
     */
    public function deferAlerts(array $itemIds, bool $shipments = false): void
    {
        DB::afterCommit(function () use ($itemIds, $shipments) {
            $this->syncTouched($itemIds, $shipments);
        });
    }

    /**
     * @param  list<int>  $itemIds
     */
    public function syncTouched(array $itemIds, bool $shipments = false): void
    {
        $itemIds = array_values(array_unique(array_map(intval(...), $itemIds)));
        $desired = [];

        if ($itemIds !== []) {
            $items = Item::query()->with('batches.balances')->whereIn('id', $itemIds)->get();
            $desired += $this->stockAlerts($items);
            $desired += $this->shortageAlerts($itemIds);
            $desired += $this->discrepancyAlerts($itemIds);
        }

        if ($shipments) {
            $desired += $this->shipmentAlerts();
        }

        $this->persistAlerts($desired, $itemIds, $shipments);
    }

    public function syncAlerts(): void
    {
        $items = Item::query()->with('batches.balances')->get();
        $desired = $this->stockAlerts($items)
            + $this->shipmentAlerts()
            + $this->shortageAlerts(null)
            + $this->discrepancyAlerts(null);

        $this->persistAlerts($desired, null, true);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array<string, array<string, mixed>>
     */
    private function stockAlerts(Collection $items): array
    {
        $desired = [];

        foreach ($items as $item) {
            $onHand = (int) $item->batches->sum(fn (Batch $batch) => $batch->balances->sum('quantity'));

            if ($item->min_level > 0 && $onHand === 0) {
                $desired['out_of_stock:'.$item->id] = $this->alert(
                    'out_of_stock',
                    'high',
                    $item->id,
                    'نفاد المخزون '.$item->code,
                    'Out of stock '.$item->code,
                    'الرصيد صفر، والحد الأدنى '.$item->min_level.'.',
                    'On-hand quantity is zero. Minimum stock is '.$item->min_level.'.',
                );
            } elseif ($item->min_level > 0 && $onHand <= $item->min_level) {
                $desired['low_stock:'.$item->id] = $this->alert(
                    'low_stock',
                    'medium',
                    $item->id,
                    'رصيد تحت الحد الأدنى '.$item->code,
                    'Below minimum stock '.$item->code,
                    'الرصيد '.$onHand.' والحد الأدنى '.$item->min_level.'.',
                    'On-hand quantity is '.$onHand.'. Minimum stock is '.$item->min_level.'.',
                );
            }

            foreach ($item->batches as $batch) {
                $qty = (int) $batch->balances->sum('quantity');

                foreach ($batch->balances as $balance) {
                    if ($balance->quantity > 0 && $balance->location_id === null) {
                        $desired['no_location:'.$balance->id] = $this->alert(
                            'no_location',
                            'medium',
                            $item->id,
                            'دفعة بانتظار التسكين '.$batch->batch_no,
                            'Batch waiting for putaway '.$batch->batch_no,
                            $item->code.' بكمية '.$balance->quantity.'.',
                            $item->code.', quantity '.$balance->quantity.'.',
                        );
                    }
                }

                if ($qty > 0 && $batch->expires_on && $batch->expires_on->lte(now()->addDays(45))) {
                    $desired['batch_expiry:'.$batch->id] = $this->alert(
                        'batch_expiry',
                        'high',
                        $item->id,
                        'قرب انتهاء الصلاحية '.$batch->batch_no,
                        'Batch nearing expiry '.$batch->batch_no,
                        'تاريخ الانتهاء '.$batch->expires_on->format('d/m/Y').'.',
                        'Expiry date '.$batch->expires_on->format('d/m/Y').'.',
                    );
                }
            }
        }

        return $desired;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function shipmentAlerts(): array
    {
        $desired = [];
        $late = Shipment::query()
            ->where('status', 'in_transit')
            ->whereDate('expected_on', '<', today())
            ->get();

        foreach ($late as $shipment) {
            $desired['shipment_delayed:'.$shipment->id] = $this->alert(
                'shipment_delayed',
                'high',
                null,
                'شحنة متأخرة '.$shipment->ci_number,
                'Delayed shipment '.$shipment->ci_number,
                'الوصول المتوقع '.$shipment->expected_on->format('d/m/Y').'.',
                'Expected arrival '.$shipment->expected_on->format('d/m/Y').'.',
            );
        }

        return $desired;
    }

    /**
     * @param  list<int>|null  $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function shortageAlerts(?array $itemIds): array
    {
        $demand = JobLine::query()
            ->with('item')
            ->whereHas('job', fn ($query) => $query->whereIn('status', ['open', 'reserved', 'partial_hold', 'in_progress']))
            ->when($itemIds !== null, fn ($query) => $query->whereIn('item_id', $itemIds))
            ->get()
            ->groupBy('item_id');

        $onHandByItem = Balance::query()
            ->join('batches', 'batches.id', '=', 'balances.batch_id')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('batches.item_id', $itemIds))
            ->selectRaw('batches.item_id as item_id, SUM(balances.quantity) as qty')
            ->groupBy('batches.item_id')
            ->pluck('qty', 'item_id');

        $desired = [];

        foreach ($demand as $itemId => $group) {
            $required = (int) $group->sum(fn (JobLine $line) => max(0, $line->required_qty - $line->issued_qty));
            $onHand = (int) ($onHandByItem[$itemId] ?? 0);

            if ($required > $onHand) {
                $item = $group->first()->item;
                $desired['shortage:'.$itemId] = $this->alert(
                    'shortage',
                    'high',
                    (int) $itemId,
                    'نقص في أمر العمل '.$item->code,
                    'Work order shortage '.$item->code,
                    'المطلوب '.$required.' والرصيد '.$onHand.'.',
                    'Required '.$required.', on hand '.$onHand.'.',
                );
            }
        }

        return $desired;
    }

    /**
     * @param  list<int>|null  $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function discrepancyAlerts(?array $itemIds): array
    {
        $variances = CountLine::query()
            ->with('item')
            ->whereHas('count', fn ($query) => $query->where('status', 'open'))
            ->whereNotNull('counted_qty')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('item_id', $itemIds))
            ->get();

        $desired = [];

        foreach ($variances as $line) {
            if ((int) $line->counted_qty === (int) $line->system_qty) {
                continue;
            }

            $desired['discrepancy:'.$line->id] = $this->alert(
                'discrepancy',
                'medium',
                $line->item_id,
                'فرق جرد '.$line->item->code,
                'Count variance '.$line->item->code,
                'كمية النظام '.$line->system_qty.' والمعدود '.$line->counted_qty.'.',
                'System quantity '.$line->system_qty.', counted quantity '.$line->counted_qty.'.',
            );
        }

        return $desired;
    }

    /**
     * @param  array<string, array<string, mixed>>  $desired
     * @param  list<int>|null  $itemIds
     */
    private function persistAlerts(array $desired, ?array $itemIds, bool $shipments): void
    {
        if ($itemIds !== null && $itemIds === [] && ! $shipments) {
            return;
        }

        $keys = array_keys($desired);
        $scoped = $this->scopedAlerts($itemIds, $shipments);

        if ($keys === []) {
            $scoped->delete();

            return;
        }

        $scoped->whereNotIn('fingerprint', $keys)->delete();

        foreach ($desired as $fingerprint => $payload) {
            $alert = Alert::query()->firstOrNew(['fingerprint' => $fingerprint]);
            $alert->fill($payload);
            $alert->resolved_at = null;
            $alert->save();
        }
    }

    /**
     * @param  list<int>|null  $itemIds
     */
    private function scopedAlerts(?array $itemIds, bool $shipments): Builder
    {
        $query = Alert::query();

        if ($itemIds === null) {
            return $query;
        }

        return $query->where(function ($inner) use ($itemIds, $shipments) {
            if ($itemIds !== []) {
                $inner->whereIn('item_id', $itemIds);
            }

            if ($shipments) {
                if ($itemIds === []) {
                    $inner->where('kind', 'shipment_delayed');
                } else {
                    $inner->orWhere('kind', 'shipment_delayed');
                }
            }
        });
    }

    private function applyReceiptToOrder(int $orderId, int $itemId, int $qty): PurchaseOrder
    {
        $line = PurchaseOrderLine::query()
            ->where('purchase_order_id', $orderId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (! $line) {
            $this->fail('purchase_order_id', 'line_missing');
        }

        $room = $line->quantity - $line->received_quantity;

        if ($qty > $room) {
            $this->fail('quantity', 'exceeds_order');
        }

        $line->received_quantity += $qty;
        $line->save();

        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $orderId)->get();
        $complete = $lines->every(fn (PurchaseOrderLine $row) => $row->received_quantity >= $row->quantity);
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
        $order->status = $complete ? 'received' : 'partial';
        $order->save();

        return $order;
    }

    private function lockPosition(int $id): Balance
    {
        $batchId = Balance::query()->whereKey($id)->value('batch_id');

        if (! $batchId) {
            $this->fail('position_id', 'line_missing');
        }

        Batch::query()->lockForUpdate()->find($batchId);

        return $this->lockBalance($id);
    }

    private function lockBalance(int $id): Balance
    {
        $balance = Balance::query()->with('batch')->lockForUpdate()->find($id);

        if (! $balance) {
            $this->fail('position_id', 'line_missing');
        }

        return $balance;
    }

    private function findLocked(int $batchId, ?int $locationId): ?Balance
    {
        $query = Balance::query()->with('batch')->where('batch_id', $batchId)->lockForUpdate();

        if ($locationId) {
            $query->where('location_id', $locationId);
        } else {
            $query->whereNull('location_id');
        }

        return $query->first();
    }

    private function addQuantity(Batch $batch, ?int $locationId, int $qty): Balance
    {
        $balance = $this->findLocked($batch->id, $locationId);

        if (! $balance) {
            return Balance::query()->create([
                'batch_id' => $batch->id,
                'location_id' => $locationId,
                'quantity' => $qty,
                'reserved' => 0,
            ]);
        }

        $balance->quantity += $qty;
        $balance->save();

        return $balance;
    }

    private function keep(Balance $balance): void
    {
        if ((int) $balance->quantity === 0 && (int) $balance->reserved === 0) {
            $balance->delete();

            return;
        }

        $balance->save();
    }

    private function guardAvailable(Balance $balance, int $qty): void
    {
        if ($qty > ($balance->quantity - $balance->reserved)) {
            $this->fail('quantity', 'insufficient');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(string $type, Batch $batch, int $quantity, int $employeeId, array $extra): void
    {
        Movement::query()->create([
            'type' => $type,
            'item_id' => $batch->item_id,
            'batch_id' => $batch->id,
            'quantity' => $quantity,
            'employee_id' => $employeeId,
            'happened_at' => now(),
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(string $kind, string $severity, ?int $itemId, string $titleAr, string $titleEn, string $detailAr, string $detailEn): array
    {
        return [
            'kind' => $kind,
            'severity' => $severity,
            'item_id' => $itemId,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'detail_ar' => $detailAr,
            'detail_en' => $detailEn,
        ];
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
