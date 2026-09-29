<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (DB::table('employees')->count() === 0) {
            $now = now();

            DB::table('employees')->insert([
                ['name' => 'راشد الكندي', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'فاطمة السيابية', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (! Schema::hasTable('balances')) {
            Schema::create('balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('reserved')->default(0);
                $table->unique(['batch_id', 'location_id']);
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('batches', 'quantity')) {
            $now = now();

            foreach (DB::table('batches')->orderBy('id')->get() as $batch) {
                if ((int) $batch->quantity === 0 && (int) $batch->reserved === 0) {
                    continue;
                }

                if (DB::table('balances')->where('batch_id', $batch->id)->exists()) {
                    continue;
                }

                DB::table('balances')->insert([
                    'batch_id' => $batch->id,
                    'location_id' => $batch->location_id,
                    'quantity' => $batch->quantity,
                    'reserved' => $batch->reserved,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasColumn('reservations', 'balance_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->foreignId('balance_id')->nullable()->after('job_line_id')->constrained()->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('reservations', 'batch_id')) {
            foreach (DB::table('reservations')->whereNull('balance_id')->orderBy('id')->get() as $reservation) {
                $balanceId = DB::table('balances')->where('batch_id', $reservation->batch_id)->value('id');

                if ($balanceId) {
                    DB::table('reservations')->where('id', $reservation->id)->update(['balance_id' => $balanceId]);
                }
            }

            DB::table('reservations')->whereNull('balance_id')->delete();

            Schema::table('reservations', function (Blueprint $table) {
                $table->dropForeign(['batch_id']);
            });

            Schema::table('reservations', function (Blueprint $table) {
                $table->dropIndex(['batch_id', 'job_line_id']);
                $table->dropColumn('batch_id');
            });
        }

        if (Schema::hasColumn('batches', 'quantity')) {
            $itemIndex = collect(Schema::getIndexes('batches'))->contains(
                fn (array $index) => $index['columns'] === ['item_id']
            );

            if (! $itemIndex) {
                Schema::table('batches', function (Blueprint $table) {
                    $table->index('item_id');
                });
            }

            Schema::table('batches', function (Blueprint $table) {
                $table->dropIndex(['item_id', 'location_id']);
                $table->dropConstrainedForeignId('location_id');
                $table->dropColumn(['quantity', 'reserved']);
            });
        }

        if (! Schema::hasColumn('movements', 'employee_id')) {
            Schema::table('movements', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->after('quantity')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $split = DB::table('balances')
            ->select('batch_id')
            ->groupBy('batch_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($split) {
            throw new RuntimeException('A batch is stored in more than one location, so this migration cannot be reversed.');
        }

        Schema::table('movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->index(['item_id', 'location_id']);
        });

        foreach (DB::table('balances')->orderBy('id')->get() as $balance) {
            DB::table('batches')->where('id', $balance->batch_id)->update([
                'location_id' => $balance->location_id,
                'quantity' => $balance->quantity,
                'reserved' => $balance->reserved,
            ]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('job_line_id')->constrained()->restrictOnDelete();
        });

        foreach (DB::table('reservations')->orderBy('id')->get() as $reservation) {
            $batchId = DB::table('balances')->where('id', $reservation->balance_id)->value('batch_id');
            DB::table('reservations')->where('id', $reservation->id)->update(['batch_id' => $batchId]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('balance_id');
            $table->index(['batch_id', 'job_line_id']);
        });

        Schema::dropIfExists('balances');
        Schema::dropIfExists('employees');
    }
};
