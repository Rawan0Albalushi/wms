<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Numbers
{
    public static function next(string $prefix, string $model): string
    {
        $floor = self::floor($model);

        DB::table('document_sequences')->insertOrIgnore([
            'prefix' => $prefix,
            'current' => $floor,
        ]);

        $current = DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->value('current');

        $next = max((int) $current, $floor) + 1;

        DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->update(['current' => $next]);

        return $prefix.'-'.$next;
    }

    private static function floor(string $model): int
    {
        $latest = $model::query()->orderByDesc('id')->value('number');

        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches)) {
            return (int) $matches[1];
        }

        return 1000;
    }
}
