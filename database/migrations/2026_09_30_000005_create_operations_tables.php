<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==================================================================
        // SYSTEM SETTINGS - rates, limits, fees, tiers, penalties, toggles.
        // ==================================================================
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->default('general')->index();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|integer|decimal|boolean|json|array
            $table->string('label', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        // ==================================================================
        // FEES & COMMISSIONS - admin configurable, never hardcoded.
        // ==================================================================
        Schema::create('fee_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('applies_to', 50)->index();   // withdrawal|transfer|funding|membership|service_commission
            $table->string('calculation', 20)->default('flat'); // flat|percent|tiered
            $table->decimal('rate', 10, 4)->default(0);  // percent value
            $table->decimal('flat_amount', 18, 2)->default(0);
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->decimal('cap_amount', 18, 2)->nullable();
            $table->boolean('bearer_member')->default(true); // member vs cooperative
            $table->json('tiers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->timestamps();
        });

        // ==================================================================
        // TRANSACTION LIMITS - keyed by KYC level / tier / product / type.
        // ==================================================================
        Schema::create('transaction_limits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('transaction_type', 40)->index(); // wallet_funding|wallet_withdrawal|transfer|savings_deposit|...
            $table->string('scope_type', 30)->default('kyc'); // kyc|tier|product|global
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->decimal('daily_limit', 18, 2)->nullable();
            $table->decimal('monthly_limit', 18, 2)->nullable();
            $table->unsignedInteger('daily_count')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['transaction_type', 'scope_type', 'scope_id']);
        });

        // ==================================================================
        // ACCOUNT HOLDS / RESTRICTIONS - every hold is audited.
        // ==================================================================
        Schema::create('account_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('hold_type', 30)->index();   // full_suspension|transaction_restriction|withdrawal_restriction|funding_restriction|temporary_hold
            $table->string('reason_code', 40)->nullable();
            $table->string('reason', 255);
            $table->boolean('is_active')->default(true);
            $table->foreignId('placed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_note')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
        });

        // ==================================================================
        // MAKER-CHECKER for sensitive financial adjustments.
        // ==================================================================
        Schema::create('adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('direction', 10);            // credit|debit
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index(); // pending|approved|rejected|posted|reversed
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('balance_before', 18, 2)->nullable();
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // AUDIT LOG
        // ==================================================================
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80)->index();
            $table->string('group', 40)->nullable()->index(); // auth|member|kyc|wallet|savings|admin|settings|reward|referral
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });

        // ==================================================================
        // NOTIFICATIONS (in-app)
        // ==================================================================
        Schema::create('member_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60)->index();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('channel', 20)->default('in_app'); // in_app|sms|push|whatsapp|email
            $table->string('severity', 20)->default('info');  // info|success|warning|critical
            $table->string('url')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'is_read', 'created_at']);
        });

        // ==================================================================
        // ANNOUNCEMENTS / NOTICE BOARD
        // ==================================================================
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('body');
            $table->string('category', 40)->default('notice'); // notice|announcement|news|update
            $table->string('severity', 20)->default('info');
            $table->string('audience', 30)->default('all_members');
            $table->boolean('pinned')->default(false);
            $table->boolean('is_published')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // SUPPORT / FAQ
        // ==================================================================
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 80)->nullable();
            $table->string('question', 255);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('subject', 180);
            $table->string('category', 60)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('open')->index(); // open|in_progress|resolved|closed
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('member_notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('adjustment_requests');
        Schema::dropIfExists('account_holds');
        Schema::dropIfExists('transaction_limits');
        Schema::dropIfExists('fee_configs');
        Schema::dropIfExists('settings');
    }
};
