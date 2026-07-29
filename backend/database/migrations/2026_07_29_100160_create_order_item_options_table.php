<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chosen variants and add-ons for a line item, snapshotted the same way as the
 * parent item so receipts stay reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('options')->nullOnDelete();

            $table->string('group_name_en');
            $table->string('group_name_ar');
            $table->enum('group_kind', ['variant', 'addon'])->default('addon');
            $table->string('option_name_en');
            $table->string('option_name_ar');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->unsignedTinyInteger('quantity')->default(1);

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
