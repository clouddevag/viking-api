<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical restaurant locations. Every table, order and staff member belongs to
 * exactly one branch, which is what scopes the kitchen and cashier screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('address_en')->nullable();
            $table->text('address_ar')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->time('opens_at')->default('10:00:00');
            $table->time('closes_at')->default('23:59:00');
            $table->string('timezone', 64)->default('Asia/Baghdad');
            $table->boolean('accepts_dine_in')->default(true);
            $table->boolean('accepts_takeaway')->default(true);
            $table->boolean('accepts_delivery')->default(false);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('minimum_order', 12, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
