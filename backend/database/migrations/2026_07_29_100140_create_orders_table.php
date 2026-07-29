<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order aggregate. Every monetary column is a snapshot taken at placement
 * time — the order must never change because a product price or coupon was
 * edited afterwards.
 *
 * Lifecycle timestamps are stored as discrete columns rather than derived from
 * `order_status_events` so the kitchen display can sort and age orders without
 * a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('table_sessions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('guest_token', 64)->nullable();

            $table->enum('type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
            $table->enum('status', [
                'pending', 'confirmed', 'preparing', 'ready',
                'served', 'completed', 'cancelled',
            ])->default('pending');
            $table->enum('payment_status', [
                'unpaid', 'paid', 'refunded', 'partially_refunded',
            ])->default('unpaid');
            $table->enum('payment_method', ['cash', 'card', 'online', 'wallet'])->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('manual_discount_total', 12, 2)->default(0);
            $table->string('manual_discount_reason')->nullable();
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->string('currency', 3)->default('IQD');

            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code', 64)->nullable();

            $table->text('notes')->nullable();
            $table->text('delivery_address')->nullable();
            $table->unsignedTinyInteger('guest_count')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'placed_at']);
            $table->index(['status', 'placed_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['guest_token', 'created_at']);
            $table->index(['payment_status', 'branch_id']);
            $table->index('dining_table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
