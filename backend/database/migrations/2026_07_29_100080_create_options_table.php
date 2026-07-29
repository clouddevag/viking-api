<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual selectable choices inside an option group. `price_delta` is added
 * to the product base price and may be negative (e.g. "no fries -1000").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained('option_groups')->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedTinyInteger('max_quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['option_group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
