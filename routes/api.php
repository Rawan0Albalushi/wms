<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CountController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy']);

    Route::middleware('active')->group(function () {
        Route::get('/me', [SessionController::class, 'show']);
        Route::get('/options', [CatalogController::class, 'options']);

        Route::get('/board', [BoardController::class, 'show'])->middleware('can:floor.view');

        Route::get('/items', [CatalogController::class, 'items'])->middleware('can:items.view');
        Route::post('/items', [CatalogController::class, 'storeItem'])->middleware('can:items.manage');
        Route::get('/items/{item}', [CatalogController::class, 'item'])->middleware('can:items.view');
        Route::patch('/items/{item}', [CatalogController::class, 'updateItem'])->middleware('can:items.manage');
        Route::delete('/items/{item}', [CatalogController::class, 'destroyItem'])->middleware('can:items.manage');
        Route::post('/items/{item}/image', [CatalogController::class, 'storeItemImage'])->middleware('can:items.manage');
        Route::delete('/items/{item}/image', [CatalogController::class, 'destroyItemImage'])->middleware('can:items.manage');

        Route::get('/suppliers', [CatalogController::class, 'suppliers'])->middleware('can:suppliers.view');
        Route::post('/suppliers', [CatalogController::class, 'storeSupplier'])->middleware('can:suppliers.manage');
        Route::patch('/suppliers/{supplier}', [CatalogController::class, 'updateSupplier'])->middleware('can:suppliers.manage');
        Route::delete('/suppliers/{supplier}', [CatalogController::class, 'destroySupplier'])->middleware('can:suppliers.manage');

        Route::get('/locations', [CatalogController::class, 'locations'])->middleware('can:locations.view');
        Route::get('/locations/{location}/history', [CatalogController::class, 'locationHistory'])->middleware('allow:locations.view,inventory.view,receiving.post,issuing.post,transfers.post');
        Route::get('/locations/{location}/stock', [CatalogController::class, 'locationStock'])->middleware('allow:locations.view,inventory.view,receiving.post,issuing.post,transfers.post');
        Route::post('/locations', [CatalogController::class, 'storeLocation'])->middleware('can:locations.manage');
        Route::patch('/locations/{location}', [CatalogController::class, 'updateLocation'])->middleware('can:locations.manage');
        Route::delete('/locations/{location}', [CatalogController::class, 'destroyLocation'])->middleware('can:locations.manage');
        Route::post('/locations/{location}/image', [CatalogController::class, 'storeLocationImage'])->middleware('can:locations.manage');
        Route::delete('/locations/{location}/image', [CatalogController::class, 'destroyLocationImage'])->middleware('can:locations.manage');

        Route::get('/procurement', [ProcurementController::class, 'index'])->middleware('allow:procurement.view,receiving.post');
        Route::post('/requests', [ProcurementController::class, 'storeRequest'])->middleware('can:procurement.manage');
        Route::post('/orders', [ProcurementController::class, 'storeOrder'])->middleware('can:procurement.manage');
        Route::post('/shipments', [ProcurementController::class, 'storeShipment'])->middleware('can:procurement.manage');
        Route::post('/shipments/{shipment}/arrive', [ProcurementController::class, 'arrive'])->middleware('can:procurement.manage');

        Route::get('/inventory', [StockController::class, 'inventory'])->middleware('allow:inventory.view,receiving.post,issuing.post,transfers.post');
        Route::post('/receipts', [StockController::class, 'receive'])->middleware('can:receiving.post');
        Route::post('/issues', [StockController::class, 'issue'])->middleware('can:issuing.post');
        Route::post('/transfers', [StockController::class, 'transfer'])->middleware('can:transfers.post');

        Route::get('/jobs', [JobController::class, 'index'])->middleware('allow:jobs.view,jobs.manage');
        Route::post('/jobs', [JobController::class, 'store'])->middleware('can:jobs.manage');
        Route::post('/jobs/{job}/reserve', [JobController::class, 'reserve'])->middleware('can:jobs.manage');
        Route::post('/jobs/{job}/issue', [JobController::class, 'issue'])->middleware('can:issuing.post');

        Route::get('/counts', [CountController::class, 'index'])->middleware('can:counts.view');
        Route::post('/counts', [CountController::class, 'store'])->middleware('can:counts.manage');
        Route::patch('/counts/{count}', [CountController::class, 'update'])->middleware('can:counts.manage');
        Route::post('/counts/{count}/approve', [CountController::class, 'approve'])->middleware('can:counts.approve');

        Route::get('/alerts', [AlertController::class, 'index'])->middleware('can:alerts.view');
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->middleware('can:alerts.view');

        Route::get('/reports', [ReportController::class, 'index'])->middleware('can:reports.view');

        Route::middleware('can:access.manage')->group(function () {
            Route::get('/access', [AccessController::class, 'index']);
            Route::post('/access/users', [AccessController::class, 'storeUser']);
            Route::patch('/access/users/{user}', [AccessController::class, 'updateUser']);
            Route::patch('/access/roles/{role}', [AccessController::class, 'updateRole']);
        });
    });
});
