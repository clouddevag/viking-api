<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the staff/customer fields the platform needs on top of Laravel's
 * default users table. Customers may register with a phone number only, so
 * `email` becomes nullable while `phone` carries its own unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')
                ->constrained('branches')->nullOnDelete();
            $table->string('phone', 32)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('avatar_path')->nullable()->after('password');
            $table->string('locale', 5)->default('ar')->after('avatar_path');
            $table->boolean('is_active')->default(true)->index()->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->softDeletes();
        });

        // Customers can sign up with a phone number instead of an email.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'branch_id', 'phone', 'phone_verified_at', 'avatar_path',
                'locale', 'is_active', 'last_login_at', 'last_login_ip', 'deleted_at',
            ]);
        });
    }
};
