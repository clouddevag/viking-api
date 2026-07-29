<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items. Product name and price are denormalised on purpose: the receipt
 * must reproduce exactly what the guest saw, even years later after the
 * product has been renamed, repriced or deleted.
 *
 * Items carry their own status so a kitchen can mark part of an order ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('product_name_en');
            $table->string('product_name_ar');
            $table->string('product_sku', 64)->nullable();
            $table->string('image_url')->nullable();

            $table->decimal('unit_price', 12, 2);
            $table->decimal('options_total', 12, 2)->default(0);
            $table->unsignedInteger('quantity');
            $table->decimal('line_subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);

            $table->text('special_instructions')->nullable();
            $table->enum('status', ['pending', 'preparing', 'ready', 'served', 'cancelled'])
                ->default('pending');
            $table->unsignedInteger('prep_time_minutes')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
