<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['prefix', 'year']);
        });

        // Product categories allow future expansion (mag wheels, batteries, ...) without
        // changing the tyre-specific search, which lives in tyre_specs.
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->boolean('has_tyre_specs')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_preferred')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tyre_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['brand_id', 'name']);
        });

        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // store | rack | workshop | used | quarantine | damaged
            $table->string('type', 30)->default('store');
            // Stock in non-sellable locations (quarantine, damaged) is physical but never available to sell.
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | archived
            $table->timestamps();
            $table->index('company_name');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tyre_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('condition', 10)->index(); // new | used
            // quantity = interchangeable batch stock (new tyres); unit = individually identified (used tyres)
            $table->string('tracking', 10)->default('quantity');
            // Unique specification key: guarantees new/used or different specs never merge into one record.
            $table->string('variant_key', 191)->nullable()->unique();

            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('promo_price', 12, 2)->nullable();
            $table->decimal('min_selling_price', 12, 2)->nullable();
            $table->decimal('fitting_fee', 12, 2)->nullable();
            $table->decimal('balancing_fee', 12, 2)->nullable();
            $table->decimal('alignment_fee', 12, 2)->nullable();

            // Cached totals, maintained only by StockLedger inside locked transactions.
            $table->unsignedInteger('qty_on_hand')->default(0);     // physical & sellable-eligible
            $table->unsignedInteger('qty_reserved')->default(0);    // active reservations
            $table->unsignedInteger('qty_unsellable')->default(0);  // physical but quarantined/damaged/uninspected
            $table->unsignedInteger('min_stock_level')->default(0);
            $table->unsignedInteger('reorder_qty')->default(0);

            $table->foreignId('default_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_sold_at')->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('status', 20)->default('active')->index(); // active | archived
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'condition']);
            $table->index('qty_on_hand');
            $table->index('selling_price');
        });

        Schema::create('tyre_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('profile')->nullable();
            $table->decimal('rim', 4, 1);
            $table->string('size_display', 30);   // e.g. 205/55 R16
            $table->string('size_key', 20);       // e.g. 205/55/16 (normalised for exact matching)
            $table->string('load_index', 10)->nullable();
            $table->string('speed_rating', 4)->nullable();
            $table->string('tyre_type', 30)->nullable();
            $table->boolean('run_flat')->default(false);
            $table->index(['width', 'profile', 'rim']);
            $table->index('rim');
            $table->index('size_key');
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->unsignedInteger('qty_on_hand')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
            $table->index('location_id');
        });

        Schema::create('tyre_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code', 30)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('tread_depth_mm', 4, 1)->nullable();
            $table->string('grade', 20)->nullable();
            $table->string('dot_code', 20)->nullable();
            $table->text('defects')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            // pending_inspection | available | reserved | sold | rejected | written_off | returned_to_supplier | missing
            $table->string('status', 30)->default('pending_inspection');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status']);
            $table->index('status');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('collection', 30)->default('images'); // images | documents
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_reserved CHECK (qty_reserved <= qty_on_hand)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('tyre_units');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('tyre_specs');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('tyre_models');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
    }
};
