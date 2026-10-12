<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_no', 30)->unique();
            $table->string('status', 20)->default('open')->index(); // open | converted | expired | cancelled
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 150)->nullable();
            $table->string('vehicle', 150)->nullable();
            $table->date('valid_until');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->boolean('vat_registered')->default(false);
            $table->boolean('prices_include_vat')->default(true);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('converted_sale_id')->nullable();
            $table->unsignedBigInteger('converted_reservation_id')->nullable();
            $table->timestamps();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20)->default('product'); // product | service
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_code', 30)->nullable();
            $table->string('description', 255);
            $table->string('size_display', 30)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_no', 30)->unique();
            $table->string('status', 20)->default('active'); // active | completed | cancelled | expired
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30);
            $table->text('notes')->nullable();
            $table->timestamp('expires_at');
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('fulfilled_qty')->default(0);
            $table->decimal('unit_price', 12, 2);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_no', 30)->unique();
            // draft | completed | voided | returned | partially_returned
            $table->string('status', 20)->default('draft')->index();
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 150)->nullable();
            $table->string('vehicle', 150)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);       // products + services before discount
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->boolean('vat_registered')->default(false);
            $table->boolean('prices_include_vat')->default(true);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('cost_total', 14, 2)->default(0);     // FIFO / unit cost of goods sold
            $table->string('payment_method', 20)->nullable();     // cash | card | eft
            $table->string('payment_status', 20)->default('unpaid'); // paid | unpaid | partial
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->string('payment_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20)->default('product'); // product | service
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('reservation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_code', 30)->nullable();
            // Snapshots preserve the historical description and price.
            $table->string('description', 255);
            $table->string('sku', 60)->nullable();
            $table->string('size_display', 30)->nullable();
            $table->string('condition', 10)->nullable();
            $table->string('brand_name', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->unsignedInteger('returned_qty')->default(0);
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending_inspection'); // pending_inspection | completed
            $table->decimal('refund_amount', 14, 2)->default(0);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0);
            // pending | restocked | damaged | written_off
            $table->string('outcome', 20)->default('pending');
            $table->foreignId('restock_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('inspection_notes', 500)->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('stocktake_no', 30)->unique();
            $table->string('title', 150);
            $table->string('type', 20); // full | brand | size | location | used
            $table->json('scope')->nullable();
            $table->boolean('is_blind')->default(true);
            // draft | in_progress | submitted | under_review | approved | closed | cancelled
            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            // Last stock movement id when counting started: movements after it happened "during the count".
            $table->unsignedBigInteger('start_movement_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->string('adjustment_reference', 40)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stocktake_user', function (Blueprint $table) {
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['stocktake_id', 'user_id']);
        });

        Schema::create('stocktake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('tyre_unit_id')->nullable()->constrained()->restrictOnDelete();
            // stocktake-scoped uniqueness: product:location[:unit] (nullable unit breaks plain unique indexes)
            $table->string('line_key', 80);
            $table->integer('snapshot_qty')->default(0);         // system quantity when the session started
            $table->integer('system_qty_at_count')->nullable();  // system quantity at the moment of counting
            $table->integer('counted_qty')->nullable();
            $table->integer('variance')->nullable();             // counted - system_qty_at_count
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('cost_impact', 14, 2)->nullable();
            $table->unsignedInteger('count_version')->default(0);
            $table->boolean('is_unexpected')->default(false);
            // pending | counted | recount_requested | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->string('recount_reason', 255)->nullable();
            $table->string('adjustment_reason', 500)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['stocktake_id', 'line_key']);
            $table->index(['product_id', 'location_id']);
        });

        Schema::create('stocktake_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_item_id')->constrained()->cascadeOnDelete();
            $table->integer('counted_qty');
            $table->integer('system_qty_at_count');
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index(); // low_stock | out_of_stock | pending_stocktake | pending_approval | reservation_expiring
            $table->string('severity', 10)->default('info'); // info | warning | danger
            $table->string('title', 200);
            $table->string('message', 500)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('permission', 80)->nullable(); // only users holding this permission see it
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // or a single user
            $table->string('dedupe_key', 150)->nullable()->unique();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['notification_id', 'user_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            $table->string('import_no', 30)->unique();
            $table->string('original_name');
            $table->string('path');
            $table->string('status', 20)->default('uploaded'); // uploaded | completed | failed
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->string('duplicate_mode', 20)->default('skip'); // skip | add
            $table->json('summary')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_imports', 'activity_logs', 'notification_reads', 'notifications', 'stocktake_counts',
            'stocktake_items', 'stocktake_user', 'stocktakes', 'sale_return_items', 'sale_returns', 'sale_items',
            'sales', 'reservation_items', 'reservations', 'quotation_items', 'quotations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
