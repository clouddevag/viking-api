<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical tables in a branch. `qr_token` is an opaque, rotatable secret that
 * appears in the QR code URL — scanning it is what identifies the table, so it
 * is never the human-readable table number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('number', 16);
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('zone', 64)->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('qr_token', 64)->unique();
            $table->timestamp('qr_rotated_at')->nullable();
            $table->enum('status', ['available', 'occupied', 'reserved', 'disabled'])
                ->default('available');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
