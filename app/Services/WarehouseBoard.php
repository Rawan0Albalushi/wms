<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Balance;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Item;
use App\Models\JobLine;
use App\Models\JobOrder;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\RecordedPosition;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\StoredImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WarehouseBoard
{
    /**
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $items = $this->itemQuery()->get();
        $presented = $items->map(fn (Item $item) => $this->presentItem($item))->values();
        $onHand = (int) $presented->sum('on_hand');
        $reserved = (int) $presented->sum('reserved');
        $value = (int) $presented->sum(fn (array $item) => $item['on_hand'] * $item['unit_cost']);
        $shortages = $this->shortages();
        $jobs = $this->jobs();
        $shipments = $this->shipments();

        return [
            'stats' => [
                'skus' => $presented->count(),
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $onHand - $reserved,
                'value' => $value,
                'pending' => collect($shortages)->where('status', 'pending')->count(),
                'alerts' => Alert::query()->count(),
                'jobs_open' => collect($jobs)->where('status', '!=', 'closed')->count(),
                'shipments_open' => collect($shipments)->where('status', '!=', 'received')->count(),
                'locations' => Location::query()->count(),
                'occupied' => (int) Balance::query()
                    ->where('quantity', '>', 0)
                    ->whereNotNull('location_id')
                    ->selectRaw('count(distinct location_id) as aggregate')
                    ->value('aggregate'),
            ],
            'tasks' => $this->tasks($shortages, $presented),
            'items' => $presented,
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'contact_name', 'phone']),
            'requests' => $this->requests(),
            'orders' => $this->orders(),
            'shipments' => $shipments,
            'jobs' => $jobs,
            'shortages' => $shortages,
            'count' => $this->openCount(),
            'alerts' => $this->alerts(),
            'movements' => $this->movements()->take(12)->values(),
            'trend' => $this->movementTrend(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(?string $term = null): array
    {
        $query = $this->itemQuery();
        $term = trim(str_replace(['%', '_'], '', (string) $term));

        if ($term !== '') {
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('code', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('part_number', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('family', 'like', "%{$term}%");
            });
        }

        return $query->get()->map(fn (Item $item) => $this->presentItem($item))->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function locations(): array
    {
        $recordedCounts = RecordedPosition::query()
            ->selectRaw('location_id, count(*) as recorded_lines')
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->pluck('recorded_lines', 'location_id');

        $locations = Location::query()
            ->withSum('balances as on_hand', 'quantity')
            ->orderBy('code')
            ->get();
        $emptyIds = $locations->filter(fn (Location $location) => (int) $location->on_hand === 0)->pluck('id');
        $cleared = $this->lastDepartures($emptyIds);
        $recordedMarks = $this->recordedMarks($emptyIds);

        return $locations
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'code' => $location->code,
                'zone' => $location->zone,
                'rack' => $location->rack,
                'shelf' => $location->shelf,
                'kind' => $location->kind,
                'image_url' => StoredImage::url($location->image_path),
                'on_hand' => (int) $location->on_hand,
                'recorded_lines' => (int) ($recordedCounts[$location->id] ?? 0),
                'cleared' => $cleared[$location->id] ?? $recordedMarks[$location->id] ?? null,
            ])
            ->all();
    }

    /**
     * Recent movements that put stock into a location or took it out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function locationHistory(Location $location): array
    {
        $movements = Movement::query()
            ->with([
                'item:id,code,name_ar,name_en',
                'fromLocation:id,code',
                'toLocation:id,code',
            ])
            ->where(function (Builder $query) use ($location) {
                $query->where('from_location_id', $location->id)
                    ->orWhere('to_location_id', $location->id);
            })
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Movement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'happened_at' => $movement->happened_at,
                'item_code' => $movement->item->code,
                'name_ar' => $movement->item->name_ar,
                'name_en' => $movement->item->name_en,
                'from' => $movement->fromLocation?->code,
                'to' => $movement->toLocation?->code,
            ])
            ->all();

        if ($movements !== []) {
            return $movements;
        }

        return RecordedPosition::query()
            ->with('item:id,code,name_ar,name_en')
            ->where('location_id', $location->id)
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (RecordedPosition $row) => [
                'id' => $row->id,
                'type' => 'recorded',
                'quantity' => $row->quantity,
                'happened_at' => optional($row->recorded_on)->toDateString(),
                'item_code' => $row->item->code,
                'name_ar' => $row->item->name_ar,
                'name_en' => $row->item->name_en,
                'from' => null,
                'to' => $location->code,
            ])
            ->all();
    }

    /**
     * Items currently stored in one location. Batches of the same item are combined.
     *
     * @return array<int, array<string, mixed>>
     */
    public function locationStock(Location $location): array
    {
        return Balance::query()
            ->with('batch.item:id,code,name_ar,name_en')
            ->where('location_id', $location->id)
            ->where('quantity', '>', 0)
            ->get()
            ->groupBy(fn (Balance $balance) => $balance->batch->item_id)
            ->map(function (Collection $balances): array {
                $item = $balances->first()->batch->item;

                return [
                    'item_id' => $item->id,
                    'item_code' => $item->code,
                    'name_ar' => $item->name_ar,
                    'name_en' => $item->name_en,
                    'quantity' => (int) $balances->sum('quantity'),
                ];
            })
            ->sortBy('item_code')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'categories' => Category::query()->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']),
            'units' => Unit::query()->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']),
            'suppliers' => Supplier::query()->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'locations' => Location::query()->orderBy('code')->get()->map(fn (Location $location) => [
                'id' => $location->id,
                'code' => $location->code,
                'zone' => $location->zone,
                'rack' => $location->rack,
                'shelf' => $location->shelf,
                'kind' => $location->kind,
                'image_url' => StoredImage::url($location->image_path),
            ]),
            'items' => Item::query()->with('unit')->orderBy('code')->get()->map(fn (Item $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name_ar' => $item->name_ar,
                'name_en' => $item->name_en,
                'unit' => $item->unit->code,
                'supplier_id' => $item->supplier_id,
                'image_url' => StoredImage::url($item->image_path),
            ]),
            'positions' => Balance::query()->with(['batch.item.unit', 'location'])->where('quantity', '>', 0)->orderBy('id')->get()->map(fn (Balance $balance) => [
                'id' => $balance->id,
                'batch_id' => $balance->batch_id,
                'batch_no' => $balance->batch->batch_no,
                'item_id' => $balance->batch->item_id,
                'item_code' => $balance->batch->item->code,
                'name_ar' => $balance->batch->item->name_ar,
                'name_en' => $balance->batch->item->name_en,
                'image_url' => StoredImage::url($balance->batch->item->image_path),
                'unit' => $balance->batch->item->unit->code,
                'location_id' => $balance->location_id,
                'location' => $balance->location?->code,
                'quantity' => $balance->quantity,
                'available' => $balance->quantity - $balance->reserved,
                'condition' => $balance->batch->condition,
            ]),
            'employees' => Employee::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function procurement(): array
    {
        return [
            'suppliers' => Supplier::query()->orderBy('code')->get(),
            'requests' => $this->requests(),
            'orders' => $this->orders(),
            'shipments' => $this->shipments(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        return [
            'locations' => $this->locations(),
            'positions' => Balance::query()
                ->with(['batch.item.unit', 'location'])
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->get()
                ->map(fn (Balance $balance) => $this->presentPosition($balance))
                ->values(),
            'recorded' => RecordedPosition::query()
                ->with(['item', 'location'])
                ->orderBy('id')
                ->get()
                ->map(fn (RecordedPosition $row) => [
                    'id' => $row->id,
                    'item_id' => $row->item_id,
                    'item_code' => $row->item->code,
                    'name_ar' => $row->item->name_ar,
                    'name_en' => $row->item->name_en,
                    'location' => $row->location?->code,
                    'quantity' => $row->quantity,
                    'condition' => $row->condition,
                    'line_type' => $row->line_type,
                    'reference' => $row->reference,
                    'recorded_on' => optional($row->recorded_on)->toDateString(),
                ])
                ->values(),
            'movements' => $this->movements(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jobs(): array
    {
        return JobOrder::query()
            ->with('lines.item.unit')
            ->orderByDesc('id')
            ->get()
            ->map(fn (JobOrder $job) => [
                'id' => $job->id,
                'number' => $job->number,
                'title' => $job->title,
                'status' => $job->status,
                'lines' => $job->lines->map(fn (JobLine $line) => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item->code,
                    'name_ar' => $line->item->name_ar,
                    'name_en' => $line->item->name_en,
                    'image_url' => StoredImage::url($line->item->image_path),
                    'unit' => $line->item->unit->code,
                    'required_qty' => $line->required_qty,
                    'reserved_qty' => $line->reserved_qty,
                    'issued_qty' => $line->issued_qty,
                ])->values(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function counts(): array
    {
        $counts = StockCount::query()->with('lines.item', 'lines.location', 'lines.batch')->orderByDesc('id')->get();

        return [
            'open' => ($open = $counts->firstWhere('status', 'open')) ? $this->presentCount($open) : null,
            'history' => $counts->where('status', '!=', 'open')->map(fn (StockCount $count) => $this->presentCount($count))->values(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        $shortageQty = collect($this->shortages())->pluck('shortage', 'item_id');

        return Alert::query()
            ->with('item.batches.balances')
            ->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderByDesc('id')
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'kind' => $alert->kind,
                'severity' => $alert->severity,
                'title_ar' => $alert->title_ar,
                'title_en' => $alert->title_en,
                'detail_ar' => $alert->detail_ar,
                'detail_en' => $alert->detail_en,
                'item_id' => $alert->item_id,
                'href' => $this->alertHref($alert, $shortageQty),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function item(Item $item): array
    {
        $item->load(['category', 'unit', 'supplier', 'batches.balances.location', 'recordedPositions.location']);
        $presented = $this->presentItem($item);
        $presented['recorded'] = $item->recordedPositions->map(fn (RecordedPosition $row) => [
            'id' => $row->id,
            'location' => $row->location?->code,
            'quantity' => $row->quantity,
            'condition' => $row->condition,
            'reference' => $row->reference,
            'line_type' => $row->line_type,
            'recorded_on' => optional($row->recorded_on)->toDateString(),
        ])->values();

        return $presented;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suppliers(): array
    {
        return Supplier::query()->orderBy('code')->get()->map(fn (Supplier $supplier) => [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'name_en' => $supplier->name_en,
            'contact_name' => $supplier->contact_name,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function reports(): array
    {
        $items = $this->itemQuery()->get()->map(fn (Item $item) => $this->presentItem($item));

        return [
            'current' => $items->map(fn (array $item) => [
                'code' => $item['code'],
                'part_number' => $item['part_number'],
                'name_ar' => $item['name_ar'],
                'name_en' => $item['name_en'],
                'image_url' => $item['image_url'],
                'unit' => $item['unit'],
                'on_hand' => $item['on_hand'],
                'available' => $item['available'],
                'value' => $item['on_hand'] * $item['unit_cost'],
            ])->values(),
            'by_location' => Balance::query()->with(['batch.item', 'location'])->where('quantity', '>', 0)->orderBy('location_id')->get()->map(fn (Balance $balance) => [
                'location' => $balance->location?->code,
                'item' => $balance->batch->item->code,
                'name_ar' => $balance->batch->item->name_ar,
                'name_en' => $balance->batch->item->name_en,
                'image_url' => StoredImage::url($balance->batch->item->image_path),
                'batch' => $balance->batch->batch_no,
                'quantity' => $balance->quantity,
                'condition' => $balance->batch->condition,
            ])->values(),
            'movements' => $this->movements(),
            'used' => $this->movements()->filter(fn (array $row) => in_array($row['type'], ['consume', 'out'], true) && $row['reason'] === 'job_order')->values(),
            'batches' => Balance::query()->with(['batch.item', 'location'])->where('quantity', '>', 0)->orderBy('id')->get()->map(fn (Balance $balance) => [
                'id' => $balance->id,
                'batch' => $balance->batch->batch_no,
                'item' => $balance->batch->item->code,
                'name_ar' => $balance->batch->item->name_ar,
                'name_en' => $balance->batch->item->name_en,
                'image_url' => StoredImage::url($balance->batch->item->image_path),
                'quantity' => $balance->quantity,
                'reserved' => $balance->reserved,
                'remaining' => $balance->quantity - $balance->reserved,
                'condition' => $balance->batch->condition,
                'location' => $balance->location?->code,
                'received_on' => optional($balance->batch->received_on)->toDateString(),
                'expires_on' => optional($balance->batch->expires_on)->toDateString(),
            ])->values(),
            'low' => $items->filter(fn (array $item) => $item['stock_status'] === 'rop' || ($item['min_level'] > 0 && $item['on_hand'] <= $item['min_level']))->map(fn (array $item) => [
                'code' => $item['code'],
                'name_ar' => $item['name_ar'],
                'name_en' => $item['name_en'],
                'image_url' => $item['image_url'],
                'on_hand' => $item['on_hand'],
                'min_level' => $item['min_level'],
                'stock_status' => $item['stock_status'],
            ])->values(),
            'counts' => StockCount::query()->with('lines.item', 'lines.location')->orderByDesc('id')->get()->map(fn (StockCount $count) => $this->presentCount($count))->values(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $shortages
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function tasks(array $shortages, Collection $items): array
    {
        $tasks = [];
        $shipments = Shipment::query()->with('purchaseOrder')->orderBy('expected_on')->get();

        foreach ($shipments->where('status', 'arrived') as $shipment) {
            $tasks[] = [
                'kind' => 'receive',
                'ref' => $shipment->ci_number,
                'href' => '/receiving?new=1&shipment='.$shipment->id.'&order='.$shipment->purchase_order_id,
            ];
        }

        $loose = Balance::query()->with('batch')->where('quantity', '>', 0)->whereNull('location_id')->orderBy('id')->get();

        foreach ($loose as $balance) {
            $tasks[] = [
                'kind' => 'putaway',
                'ref' => $balance->batch->batch_no,
                'href' => '/transfers?new=1&position='.$balance->id,
            ];
        }

        $jobs = JobOrder::query()
            ->with('lines')
            ->whereIn('status', ['open', 'reserved', 'partial_hold', 'in_progress'])
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            $reserved = (int) $job->lines->sum('reserved_qty');
            $gap = (int) $job->lines->sum(fn (JobLine $line) => max(0, $line->required_qty - $line->reserved_qty - $line->issued_qty));

            if ($reserved > 0) {
                $tasks[] = ['kind' => 'issue', 'ref' => $job->number, 'href' => '/jobs/'.$job->id];
            } elseif ($gap > 0) {
                $tasks[] = ['kind' => 'reserve', 'ref' => $job->number, 'href' => '/jobs/'.$job->id];
            }
        }

        $bought = [];

        foreach ($shortages as $row) {
            if ($row['shortage'] <= 0) {
                continue;
            }

            $bought[] = $row['code'];
            $tasks[] = [
                'kind' => 'buy',
                'ref' => $row['code'],
                'href' => '/procurement?new=request&item='.$row['item_id'].'&qty='.$row['shortage'],
            ];
        }

        foreach ($shipments->where('status', 'in_transit') as $shipment) {
            $tasks[] = [
                'kind' => 'arrive',
                'ref' => $shipment->ci_number,
                'href' => '/procurement/shipments/'.$shipment->id,
            ];
        }

        $count = StockCount::query()->with('lines')->where('status', 'open')->first();

        if ($count) {
            $ready = $count->lines->every(fn ($line) => $line->counted_qty !== null);
            $tasks[] = [
                'kind' => $ready ? 'approve' : 'count',
                'ref' => $count->number,
                'href' => '/counts',
            ];
        }

        foreach ($items as $item) {
            if ($item['min_level'] > 0 && $item['on_hand'] <= $item['min_level'] && ! in_array($item['code'], $bought, true)) {
                $qty = max(1, $item['min_level'] - $item['on_hand']);
                $tasks[] = [
                    'kind' => 'buy',
                    'ref' => $item['code'],
                    'href' => '/procurement?new=request&item='.$item['id'].'&qty='.$qty,
                ];
            }
        }

        return array_slice($tasks, 0, 12);
    }

    /**
     * @param  Collection<int|string, mixed>  $shortageQty
     */
    private function alertHref(Alert $alert, Collection $shortageQty): ?string
    {
        $subject = (int) substr((string) $alert->fingerprint, (int) strpos((string) $alert->fingerprint, ':') + 1);

        return match ($alert->kind) {
            'out_of_stock', 'low_stock' => $this->buyHref($alert->item_id, $this->replenishQty($alert)),
            'shortage' => $this->buyHref($alert->item_id, (int) ($shortageQty[$alert->item_id] ?? 1)),
            'no_location' => '/transfers?new=1&position='.$subject,
            'shipment_delayed' => '/procurement/shipments/'.$subject,
            'discrepancy' => '/counts',
            'batch_expiry' => $alert->item_id ? '/items/'.$alert->item_id : '/inventory',
            default => null,
        };
    }

    private function replenishQty(Alert $alert): int
    {
        if (! $alert->item) {
            return 1;
        }

        $onHand = (int) $alert->item->batches->sum(fn (Batch $batch) => $batch->balances->sum('quantity'));

        return max(1, $alert->item->min_level - $onHand);
    }

    private function buyHref(?int $itemId, int $qty): string
    {
        if (! $itemId) {
            return '/procurement?new=request';
        }

        return '/procurement?new=request&item='.$itemId.'&qty='.$qty;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(Item $item): array
    {
        $onHand = (int) $item->batches->sum(fn (Batch $batch) => $batch->balances->sum('quantity'));
        $reserved = (int) $item->batches->sum(fn (Batch $batch) => $batch->balances->sum('reserved'));

        return [
            'id' => $item->id,
            'code' => $item->code,
            'part_number' => $item->part_number,
            'barcode' => $item->barcode,
            'image_url' => StoredImage::url($item->image_path),
            'name_ar' => $item->name_ar,
            'name_en' => $item->name_en,
            'min_level' => $item->min_level,
            'unit_cost' => $item->unit_cost,
            'category_id' => $item->category_id,
            'unit_id' => $item->unit_id,
            'supplier_id' => $item->supplier_id,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $onHand - $reserved,
            'unit' => $item->unit->code,
            'stock_status' => $item->stock_status,
            'family' => $item->family,
            'alt_unit' => $item->alt_unit,
            'category' => [
                'id' => $item->category->id,
                'name_ar' => $item->category->name_ar,
                'name_en' => $item->category->name_en,
            ],
            'supplier' => $item->supplier ? [
                'id' => $item->supplier->id,
                'name' => $item->supplier->name,
                'name_en' => $item->supplier->name_en,
                'phone' => $item->supplier->phone,
            ] : null,
            'locations' => $item->batches
                ->flatMap(fn (Batch $batch) => $batch->balances->map(fn (Balance $balance) => $balance->location?->code))
                ->filter()
                ->unique()
                ->values(),
            'recorded_locations' => $item->relationLoaded('recordedPositions')
                ? $item->recordedPositions->map(fn (RecordedPosition $row) => $row->location?->code)->filter()->unique()->values()
                : [],
            'batches' => $item->batches
                ->map(function (Batch $batch) {
                    $positions = $batch->balances->where('quantity', '>', 0)->values();

                    return [
                        'id' => $batch->id,
                        'batch_no' => $batch->batch_no,
                        'quantity' => (int) $positions->sum('quantity'),
                        'reserved' => (int) $positions->sum('reserved'),
                        'condition' => $batch->condition,
                        'received_on' => optional($batch->received_on)->toDateString(),
                        'expires_on' => optional($batch->expires_on)->toDateString(),
                        'reference' => $batch->reference,
                        'positions' => $positions->map(fn (Balance $balance) => [
                            'id' => $balance->id,
                            'quantity' => $balance->quantity,
                            'reserved' => $balance->reserved,
                            'available' => $balance->quantity - $balance->reserved,
                            'location' => $balance->location?->code,
                            'location_id' => $balance->location_id,
                        ])->values(),
                    ];
                })
                ->filter(fn (array $batch) => $batch['positions']->isNotEmpty())
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPosition(Balance $balance): array
    {
        return [
            'id' => $balance->id,
            'batch_id' => $balance->batch_id,
            'batch_no' => $balance->batch->batch_no,
            'quantity' => $balance->quantity,
            'reserved' => $balance->reserved,
            'available' => $balance->quantity - $balance->reserved,
            'condition' => $balance->batch->condition,
            'received_on' => optional($balance->batch->received_on)->toDateString(),
            'expires_on' => optional($balance->batch->expires_on)->toDateString(),
            'reference' => $balance->batch->reference,
            'item_id' => $balance->batch->item_id,
            'item_code' => $balance->batch->item->code,
            'name_ar' => $balance->batch->item->name_ar,
            'name_en' => $balance->batch->item->name_en,
            'image_url' => StoredImage::url($balance->batch->item->image_path),
            'unit' => $balance->batch->item->unit->code,
            'location_id' => $balance->location_id,
            'location' => $balance->location?->code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCount(StockCount $count): array
    {
        return [
            'id' => $count->id,
            'number' => $count->number,
            'status' => $count->status,
            'started_at' => $count->started_at,
            'approved_at' => $count->approved_at,
            'lines' => $count->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_code' => $line->item->code,
                'name_ar' => $line->item->name_ar,
                'name_en' => $line->item->name_en,
                'image_url' => StoredImage::url($line->item->image_path),
                'location' => $line->location?->code,
                'batch_no' => $line->batch?->batch_no,
                'system_qty' => $line->system_qty,
                'counted_qty' => $line->counted_qty,
                'variance' => $line->variance,
            ])->values(),
        ];
    }

    /**
     * @return Builder<Item>
     */
    private function itemQuery(): Builder
    {
        return Item::query()->with(['category', 'unit', 'supplier', 'batches.balances.location', 'recordedPositions.location'])->orderBy('code');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requests(): array
    {
        return PurchaseRequest::query()->with('item')->orderByDesc('id')->get()->map(fn (PurchaseRequest $request) => [
            'id' => $request->id,
            'number' => $request->number,
            'status' => $request->status,
            'quantity' => $request->quantity,
            'note' => $request->note,
            'item_code' => $request->item->code,
            'name_ar' => $request->item->name_ar,
            'name_en' => $request->item->name_en,
            'image_url' => StoredImage::url($request->item->image_path),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orders(): array
    {
        return PurchaseOrder::query()->with(['supplier', 'lines.item'])->withCount('shipments')->orderByDesc('id')->get()->map(fn (PurchaseOrder $order) => [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $this->archivedFuture($order) ? 'historical' : $order->status,
            'kind' => $this->archivedFuture($order) ? 'future' : ($order->kind ?: 'receipt'),
            'notes' => $order->notes,
            'ordered_on' => optional($order->ordered_on)->toDateString(),
            'supplier' => $order->supplier->name,
            'supplier_en' => $order->supplier->name_en,
            'lines' => $order->lines->map(fn ($line) => [
                'item_id' => $line->item_id,
                'item_code' => $line->item->code,
                'name_ar' => $line->item->name_ar,
                'name_en' => $line->item->name_en,
                'image_url' => StoredImage::url($line->item->image_path),
                'quantity' => $line->quantity,
                'received_quantity' => $line->received_quantity,
                'remaining' => $line->quantity - $line->received_quantity,
            ])->values(),
        ])->all();
    }

    private function archivedFuture(PurchaseOrder $order): bool
    {
        return ($order->kind ?: 'receipt') === 'receipt'
            && str_starts_with(strtoupper($order->number), 'RFQ-')
            && (int) $order->shipments_count === 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shipments(): array
    {
        return Shipment::query()->with('purchaseOrder.supplier')->orderByDesc('id')->get()->map(fn (Shipment $shipment) => [
            'id' => $shipment->id,
            'ci_number' => $shipment->ci_number,
            'status' => $shipment->status,
            'expected_on' => optional($shipment->expected_on)->toDateString(),
            'packing_note' => $shipment->packing_note,
            'order_id' => $shipment->purchase_order_id,
            'order_number' => $shipment->purchaseOrder->number,
            'supplier' => $shipment->purchaseOrder->supplier->name,
            'supplier_en' => $shipment->purchaseOrder->supplier->name_en,
            'delayed' => $shipment->status === 'in_transit' && $shipment->expected_on->lt(today()),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function shortages(): array
    {
        $groups = JobLine::query()
            ->with('item')
            ->whereHas('job', fn ($query) => $query->whereIn('status', ['open', 'reserved', 'partial_hold', 'in_progress']))
            ->get()
            ->groupBy('item_id');

        $onHand = Balance::query()
            ->join('batches', 'batches.id', '=', 'balances.batch_id')
            ->selectRaw('batches.item_id as item_id, SUM(balances.quantity) as qty')
            ->groupBy('batches.item_id')
            ->pluck('qty', 'item_id');
        $rows = [];

        foreach ($groups as $itemId => $group) {
            $item = $group->first()->item;
            $required = (int) $group->sum(fn (JobLine $line) => max(0, $line->required_qty - $line->issued_qty));
            $available = (int) ($onHand[$itemId] ?? 0);
            $shortage = max(0, $required - $available);
            $rows[] = [
                'item_id' => $item->id,
                'code' => $item->code,
                'name_ar' => $item->name_ar,
                'name_en' => $item->name_en,
                'image_url' => StoredImage::url($item->image_path),
                'required' => $required,
                'available' => $available,
                'shortage' => $shortage,
                'status' => $shortage > 0 ? 'pending' : 'ok',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function openCount(): ?array
    {
        $count = StockCount::query()->with('lines.item', 'lines.location', 'lines.batch')->where('status', 'open')->first();

        return $count ? $this->presentCount($count) : null;
    }

    /**
     * Inbound and outbound quantities for the twelve weeks ending today.
     *
     * @return array<int, array{date: string, in: int, out: int}>
     */
    private function movementTrend(): array
    {
        $end = now()->startOfDay()->addDay();
        $start = $end->copy()->subWeeks(12);
        $rows = Movement::query()
            ->where('happened_at', '>=', $start)
            ->where('happened_at', '<', $end)
            ->get(['id', 'type', 'quantity', 'happened_at']);

        return collect(range(0, 11))->map(function (int $offset) use ($start, $rows) {
            $from = $start->copy()->addWeeks($offset);
            $to = $from->copy()->addWeek();
            $slice = $rows->filter(fn (Movement $row) => $row->happened_at->greaterThanOrEqualTo($from) && $row->happened_at->lessThan($to));

            return [
                'date' => $from->toDateString(),
                'in' => (int) $slice->where('type', 'in')->sum('quantity'),
                'out' => (int) $slice->whereIn('type', ['out', 'consume'])->sum('quantity'),
            ];
        })            ->all();
    }

    /**
     * Latest workbook line for an empty location that has no stock movement.
     *
     * @param  Collection<int, int>  $locationIds
     * @return array<int, array<string, mixed>>
     */
    private function recordedMarks(Collection $locationIds): array
    {
        if ($locationIds->isEmpty()) {
            return [];
        }

        $marks = [];

        RecordedPosition::query()
            ->with('item:id,code,name_ar,name_en')
            ->whereIn('location_id', $locationIds)
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->get()
            ->each(function (RecordedPosition $row) use (&$marks) {
                if (isset($marks[$row->location_id])) {
                    return;
                }

                $marks[$row->location_id] = [
                    'item_code' => $row->item->code,
                    'name_ar' => $row->item->name_ar,
                    'name_en' => $row->item->name_en,
                    'quantity' => $row->quantity,
                    'type' => 'recorded',
                    'happened_at' => optional($row->recorded_on)->toDateString(),
                ];
            });

        return $marks;
    }

    /**
     * Last movement that took stock out of each currently empty location.
     *
     * @param  Collection<int, int>  $locationIds
     * @return array<int, array<string, mixed>>
     */
    private function lastDepartures(Collection $locationIds): array
    {
        if ($locationIds->isEmpty()) {
            return [];
        }

        $ids = Movement::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('from_location_id', $locationIds)
            ->groupBy('from_location_id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Movement::query()
            ->with('item:id,code,name_ar,name_en')
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Movement $movement) => [
                $movement->from_location_id => [
                    'item_code' => $movement->item->code,
                    'name_ar' => $movement->item->name_ar,
                    'name_en' => $movement->item->name_en,
                    'quantity' => $movement->quantity,
                    'type' => $movement->type,
                    'happened_at' => $movement->happened_at,
                ],
            ])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function movements(): Collection
    {
        return Movement::query()
            ->with(['item', 'batch', 'fromLocation', 'toLocation', 'employee'])
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Movement $movement) {
                $sign = match ($movement->type) {
                    'in' => 1,
                    'out', 'consume' => -1,
                    'adjustment' => $movement->to_location_id ? 1 : -1,
                    default => 0,
                };

                return [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'signed' => $sign === 0 ? $movement->quantity : $sign * $movement->quantity,
                    'reason' => $movement->reason,
                    'department' => $movement->department,
                    'reference' => $movement->reference,
                    'happened_at' => $movement->happened_at,
                    'item' => $movement->item->code,
                    'name_ar' => $movement->item->name_ar,
                    'name_en' => $movement->item->name_en,
                    'image_url' => StoredImage::url($movement->item->image_path),
                    'batch' => $movement->batch?->batch_no,
                    'employee' => $movement->employee?->name,
                    'from' => $movement->fromLocation?->code,
                    'to' => $movement->toLocation?->code,
                ];
            });
    }
}
