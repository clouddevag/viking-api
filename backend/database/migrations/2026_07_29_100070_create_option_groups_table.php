<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single structure backs both variants and add-ons, because they only differ
 * in intent: a `variant` group is a required single choice that defines the
 * item (size, doneness), an `addon` group is an optional multi-choice that
 * extends it (extra cheese, sauces).
 *
 * `product_id` is nullable — a null group is a reusable library group attached
 * to many products through `option_group_product`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('description_en')->nullable();
            $table->string('description_ar')->nullable();
            $table->enum('kind', ['variant', 'addon'])->default('addon');
            $table->enum('selection', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('min_selections')->default(0);
            $table->unsignedTinyInteger('max_selections')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index(['kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_groups');
    }
};
