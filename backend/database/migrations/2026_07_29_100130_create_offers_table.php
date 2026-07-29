<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchandising campaigns: home-screen banners, combo deals and automatic
 * percentage discounts on a set of products. Unlike coupons these need no code
 * and apply automatically while active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->enum('type', ['banner', 'combo', 'discount'])->default('banner');
            $table->enum('discount_type', ['fixed', 'percentage'])->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('combo_price', 12, 2)->nullable();
            $table->string('cta_url')->nullable();
            $table->string('badge_en', 64)->nullable();
            $table->string('badge_ar', 64)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        Schema::create('offer_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity')->default(1);

            $table->unique(['offer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_product');
        Schema::dropIfExists('offers');
    }
};
