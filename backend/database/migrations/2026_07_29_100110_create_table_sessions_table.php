<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One dining visit. Created when a guest scans a table QR and closed by the
 * cashier at settlement, so multiple orders placed during the same visit roll
 * up to a single bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_table_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_token', 64)->unique();
            $table->string('guest_name')->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->unsignedTinyInteger('party_size')->default(1);
            $table->enum('status', ['open', 'closed', 'abandoned'])->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['dining_table_id', 'status']);
            $table->index(['branch_id', 'status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
