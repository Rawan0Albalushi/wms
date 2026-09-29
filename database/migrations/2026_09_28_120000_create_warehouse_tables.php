<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('name_en');
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('part_number', 40)->nullable()->index();
            $table->string('barcode', 40)->nullable()->index();
            $table->string('name_ar');
            $table->string('name_en');
            $table->foreignId('category_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('min_level')->default(0);
            $table->unsignedInteger('unit_cost')->default(0);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('zone', 20);
            $table->string('rack', 10)->nullable();
            $table->string('shelf', 10)->nullable();
            $table->string('kind', 20)->default('storage');
            $table->index(['zone', 'rack']);
            $table->timestamps();
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no', 40)->unique();
            $table->date('received_on');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reserved')->default(0);
            $table->string('condition', 20);
            $table->string('reference', 80)->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->index(['item_id', 'location_id']);
            $table->timestamps();
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('open');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('ordered');
            $table->date('ordered_on');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->timestamps();
            $table->unique(['purchase_order_id', 'item_id']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->string('ci_number', 40)->unique();
            $table->date('expected_on')->index();
            $table->string('status', 20)->default('in_transit');
            $table->string('packing_note')->nullable();
            $table->timestamps();
        });

        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('title');
            $table->string('status', 20)->default('open');
            $table->timestamps();
        });

        Schema::create('job_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->unsignedInteger('required_qty');
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->unsignedInteger('issued_qty')->default(0);
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->index(['batch_id', 'job_line_id']);
            $table->timestamps();
        });

        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 40)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('reference', 80)->nullable();
            $table->timestamp('happened_at')->index();
            $table->timestamps();
        });

        Schema::create('counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('open');
            $table->timestamp('started_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('counts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('system_qty');
            $table->unsignedInteger('counted_qty')->nullable();
            $table->integer('variance')->nullable();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->string('kind', 40)->index();
            $table->string('severity', 20);
            $table->string('title_ar');
            $table->string('title_en');
            $table->string('detail_ar');
            $table->string('detail_en');
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('count_lines');
        Schema::dropIfExists('counts');
        Schema::dropIfExists('movements');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('job_lines');
        Schema::dropIfExists('job_orders');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('items');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
