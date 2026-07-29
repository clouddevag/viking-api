<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment attempts against an order. An order may have several rows (split
 * payments, a failed card followed by cash), so `payment_status` on the order
 * is derived from the sum of captured payments rather than the latest row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'online', 'wallet'])->default('cash');
            $table->enum('status', ['pending', 'captured', 'failed', 'refunded'])->default('pending');
            $table->decimal('amount', 12, 2);
            $table->decimal('tendered_amount', 12, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->string('reference', 128)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
