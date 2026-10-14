<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a member's browser can be reached with a push message.
 *
 * One row per device. A member who signs in on a phone and a laptop holds
 * two, and revoking one - signing out, clearing site data, turning
 * notifications off - must not silence the other.
 *
 * The endpoint is what the push service (FCM for Chrome and Android, the
 * Mozilla push service for Firefox) issued, and it is the only identity
 * that matters: the encryption keys below belong to the device, not to the
 * person, and a leaked pair can send nothing to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Issued by the push service and unique to the device. This is
            // the address, so it is unique: the same browser subscribing
            // twice must refresh its keys, not create a second target.
            $table->text('endpoint')->unique();

            // Client-side ECDH keys. Public key and auth secret, both base64url.
            $table->string('p256dh', 120);
            $table->string('auth', 60);

            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('platform', 30)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
