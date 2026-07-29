<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attaches reusable (product_id = null) option groups to products, with a
 * per-product sort override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained('option_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['option_group_id', 'product_id'], 'option_group_product_unique');
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_group_product');
    }
};
