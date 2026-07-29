<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central media library. Uploads land here first and are then referenced by
 * products, categories and offers, which lets the admin panel reuse an image
 * without re-uploading it and keeps orphan cleanup to a single table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('conversions_path')->nullable();
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('collection', 64)->default('default')->index();
            $table->string('alt_en')->nullable();
            $table->string('alt_ar')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['collection', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
