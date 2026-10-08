<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email')->index();
            $table->string('avatar')->nullable()->after('password');
            $table->string('transaction_pin')->nullable()->after('password');
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_admin');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'avatar', 'transaction_pin', 'is_admin',
                'is_active', 'last_login_at', 'password_changed_at',
            ]);
        });
    }
};
