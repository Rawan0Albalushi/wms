<?php

use App\Services\CompleteSparePartsCatalog;
use App\Services\StockLedger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('spare-parts:complete {path} {--apply : Write missing catalog rows. Existing balances, movements, and orders stay as they are.}', function (string $path, CompleteSparePartsCatalog $catalog) {
    $summary = $catalog->complete($path, (bool) $this->option('apply'));

    foreach ($summary as $key => $value) {
        $this->line($key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
    }

    if (! $this->option('apply')) {
        $this->comment('Nothing was written. Run the same command with --apply to add the missing rows.');
    }
})->purpose('Add workbook rows that are not already in the catalog');

Artisan::command('alerts:sync', function (StockLedger $ledger) {
    $ledger->syncAlerts();
    $this->info('Alerts refreshed.');
})->purpose('Refresh expiry, shortage, and shipment alerts');

Schedule::command('alerts:sync')->everyFifteenMinutes()->withoutOverlapping(30);
