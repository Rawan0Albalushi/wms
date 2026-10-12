<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('part_number', 80)->nullable()->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('stock_status', 20)->nullable()->after('unit_cost');
            $table->string('family', 80)->nullable()->after('stock_status');
            $table->string('alt_unit', 10)->nullable()->after('family');
            $table->index('stock_status');
            $table->index('family');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('code', 100)->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('kind', 20)->default('receipt')->after('status');
            $table->index('kind');
        });

        Schema::create('recorded_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('condition', 20)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('line_type', 40)->nullable();
            $table->date('recorded_on')->nullable();
            $table->string('import_key', 64)->unique();
            $table->timestamps();
            $table->index(['item_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorded_positions');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('code', 20)->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['stock_status']);
            $table->dropIndex(['family']);
            $table->dropColumn(['stock_status', 'family', 'alt_unit']);
            $table->string('part_number', 40)->nullable()->change();
        });
    }
};
