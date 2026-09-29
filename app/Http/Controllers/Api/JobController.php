<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Services\DocumentFlow;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(WarehouseBoard $board): array
    {
        return [
            'jobs' => $board->jobs(),
            'shortages' => $board->shortages(),
        ];
    }

    public function store(Request $request, DocumentFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $created = $flow->job($data);

        return response()->json(['ok' => true, 'id' => $created->id, 'number' => $created->number], 201);
    }

    public function reserve(JobOrder $job, StockLedger $ledger): array
    {
        $ledger->reserveJob($job);

        return ['ok' => true];
    }

    public function issue(Request $request, JobOrder $job, StockLedger $ledger): array
    {
        $ledger->issueJob($job, Actor::id($request));

        return ['ok' => true];
    }
}
