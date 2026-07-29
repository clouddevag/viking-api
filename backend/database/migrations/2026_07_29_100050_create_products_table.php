<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu items. `base_price` is the price before any option deltas; the final
 * line price is computed at order time and snapshotted onto the order item so
 * historical orders survive menu edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('slug')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('short_description_en', 255)->nullable();
            $table->string('short_description_ar', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->decimal('base_price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();

            $table->unsignedInteger('calories')->nullable();
            $table->unsignedInteger('prep_time_minutes')->default(10);
            $table->unsignedTinyInteger('spice_level')->default(0);
            $table->json('allergens')->nullable();
            $table->json('tags')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('order_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'sort_order']);
            $table->index(['is_active', 'is_available']);
            $table->index(['is_featured', 'is_active']);
            $table->index('order_count');
        });

        // Full-text search over both locales; MySQL/MariaDB only, the SQLite
        // test database falls back to LIKE matching in the repository layer.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE products ADD FULLTEXT products_search_fulltext '
                .'(name_en, name_ar, short_description_en, short_description_ar)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
