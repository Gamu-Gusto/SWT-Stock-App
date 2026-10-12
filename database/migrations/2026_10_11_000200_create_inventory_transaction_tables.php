<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_reference', 80)->nullable(); // delivery note / invoice number
            $table->date('delivery_date');
            $table->string('status', 20)->default('draft')->index(); // draft | posted | reversed
            $table->text('notes')->nullable();
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'supplier_reference']);
            $table->index('delivery_date');
        });

        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 14, 2);
            // For individually tracked (used) tyres: per-unit details captured on the receipt.
            $table->json('unit_details')->nullable();
            $table->timestamps();
        });

        // FIFO cost layers for quantity-tracked stock.
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 30); // receipt | opening | import | adjustment | return | stocktake
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('stock_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 12, 2);
            $table->unsignedInteger('qty_received');
            $table->unsignedInteger('qty_remaining');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['product_id', 'qty_remaining', 'received_at', 'id']);
        });

        // Immutable inventory ledger. Rows are never updated or deleted by the application.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('type', 30)->index();
            $table->integer('quantity_change');
            $table->unsignedInteger('previous_qty');   // location balance before
            $table->unsignedInteger('resulting_qty');  // location balance after
            $table->foreignId('from_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no', 40)->nullable()->index();
            $table->string('reason', 500)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['location_id', 'created_at']);
        });

        Schema::create('stock_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique();
            $table->foreignId('from_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 20)->default('completed');
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 30)->unique();
            // manual_adjustment | damaged | write_off | supplier_return
            $table->string('type', 30);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity_change');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_requests');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_lot_allocations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
    }
};
