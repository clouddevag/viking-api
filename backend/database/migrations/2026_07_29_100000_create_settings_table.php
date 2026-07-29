<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value application settings, grouped so the admin panel can render them
 * as tabs. `is_public` settings are exposed to unauthenticated clients through
 * the bootstrap endpoint; everything else stays behind an admin token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type', 32)->default('string');
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
