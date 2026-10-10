<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member may change the address on their account, but only after proving
     * control of the new one. One pending request at a time per account; the
     * raw token is emailed and never stored - the row keeps a hash, an expiry,
     * and which address it came from, so an unexplained change is traceable.
     */
    public function up(): void
    {
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_email', 190);
            $table->string('new_email', 190);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
