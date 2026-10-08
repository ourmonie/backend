<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Membership tiers - fully admin configurable, never hardcoded.
        // ------------------------------------------------------------------
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->decimal('min_threshold', 18, 2)->default(0);
            $table->decimal('max_threshold', 18, 2)->nullable();
            $table->json('benefits')->nullable();
            $table->decimal('max_wallet_funding', 18, 2)->nullable();
            $table->decimal('max_transfer', 18, 2)->nullable();
            $table->decimal('max_withdrawal', 18, 2)->nullable();
            $table->decimal('rewards_multiplier', 8, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ------------------------------------------------------------------
        // Members - one per user. Generic employment info, never teacher-only.
        // ------------------------------------------------------------------
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('membership_tier_id')->nullable()->constrained('membership_tiers')->nullOnDelete();
            $table->string('member_number', 20)->unique();
            $table->string('referral_code', 20)->unique();

            // Membership state
            $table->string('membership_status', 30)->default('pending')->index(); // pending|active|suspended|closed
            $table->string('kyc_status', 30)->default('not_started')->index();
            $table->unsignedTinyInteger('kyc_level')->default(0);
            $table->timestamp('member_since')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Profile
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('avatar')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();

            // Compliance identifiers
            $table->string('bvn', 20)->nullable()->index();
            $table->string('nin', 20)->nullable()->index();

            // Employment (generic - no teacher/school restriction)
            $table->string('occupation', 100)->nullable();
            $table->string('employer', 150)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('employment_status', 40)->nullable();

            // Referral linkage
            $table->foreignId('referred_by')->nullable()->constrained('members')->nullOnDelete();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['membership_status', 'kyc_status']);
        });

        // ------------------------------------------------------------------
        // KYC submissions: hybrid automated verification + admin review.
        // ------------------------------------------------------------------
        Schema::create('kyc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('status', 30)->default('submitted')->index();
            $table->json('data')->nullable();
            $table->json('documents')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('provider_response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_records');
        Schema::dropIfExists('members');
        Schema::dropIfExists('membership_tiers');
    }
};
