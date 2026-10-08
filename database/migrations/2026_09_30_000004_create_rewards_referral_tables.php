<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==================================================================
        // REWARD RULES - fully configurable earning/conversion engine.
        // ==================================================================
        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('activity', 50)->index();      // join|monthly_contribution|saving|referral|campaign
            $table->string('trigger_event', 80)->nullable();
            $table->decimal('points', 12, 2)->default(0);
            $table->string('points_type', 20)->default('fixed'); // fixed|percent_of_amount
            $table->decimal('max_points', 12, 2)->nullable();
            $table->decimal('min_qualifying_amount', 18, 2)->nullable();
            $table->unsignedInteger('max_uses_per_member')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('reward_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('points_balance')->default(0);
            $table->bigInteger('lifetime_earned')->default(0);
            $table->bigInteger('lifetime_redeemed')->default(0);
            $table->bigInteger('pending_points')->default(0);
            $table->timestamps();
        });

        Schema::create('reward_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_rule_id')->nullable()->constrained('reward_rules')->nullOnDelete();
            $table->string('entry_type', 20)->index();    // earn|redeem|expire|reverse|adjustment
            $table->bigInteger('points');                 // signed
            $table->bigInteger('balance_after')->nullable();
            $table->string('activity', 50)->nullable();
            $table->string('reference', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
        });

        // Conversion config lives in reward_conversion_tiers
        Schema::create('reward_conversion_tiers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('min_points');
            $table->bigInteger('max_points')->nullable();
            $table->decimal('cash_value', 18, 2);          // NGN for the min_points block
            $table->string('label', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ==================================================================
        // REFERRALS - full lifecycle tracking.
        // ==================================================================
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('referee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referee_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('code', 20)->index();
            $table->string('status', 40)->default('clicked')->index();
            // clicked|registered|kyc_pending|kyc_verified|first_contribution_pending|qualified|reward_issued|reward_cancelled
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('first_contribution_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->bigInteger('reward_points')->default(0);
            $table->decimal('reward_cash', 18, 2)->default(0);
            $table->timestamp('reward_issued_at')->nullable();
            $table->foreignId('reward_entry_id')->nullable()->constrained('reward_entries')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['referrer_member_id', 'referee_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('reward_conversion_tiers');
        Schema::dropIfExists('reward_entries');
        Schema::dropIfExists('reward_accounts');
        Schema::dropIfExists('reward_rules');
    }
};
