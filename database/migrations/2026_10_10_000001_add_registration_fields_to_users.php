<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cooperative comms run on WhatsApp, so it is a first-class contact.
            $table->string('whatsapp', 30)->nullable()->after('phone')->index();
            // Age gate: membership is 18+ and the NIN is the identity behind it.
            $table->string('nin', 11)->nullable()->after('whatsapp')->index();
            $table->timestamp('nin_verified_at')->nullable()->after('nin');
            $table->date('date_of_birth')->nullable()->after('nin_verified_at');
            // Social sign-in (Google) links to the same member record.
            $table->string('google_id', 64)->nullable()->after('avatar')->unique();
            // One-time product tour, required for every member.
            $table->timestamp('onboarded_at')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp', 'nin', 'nin_verified_at', 'date_of_birth',
                'google_id', 'onboarded_at',
            ]);
        });
    }
};
