<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==================================================================
        // CHART OF ACCOUNTS
        // The authoritative structure that separates member-owned funds
        // from cooperative income, fees, rewards and interest.
        // ==================================================================
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('type', 20)->index();       // asset|liability|equity|revenue|expense
            $table->string('normal_side', 10)->default('debit'); // debit|credit
            $table->string('group', 40)->index();      // member_funds|cooperative_income|fees|rewards|interest|clearing|equity
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['group', 'type']);
        });

        // ==================================================================
        // MEMBER ACCOUNTS
        // One row per member per financial product. Balances here are
        // DERIVED from ledger postings, never edited directly.
        // ==================================================================
        Schema::create('member_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();      // wallet|swift_save|quest_save|time_vault
            $table->string('account_number', 20)->unique();
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('total_in', 18, 2)->default(0);
            $table->decimal('total_out', 18, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('active'); // active|restricted|frozen|closed
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'type']);
            $table->index(['type', 'status']);
        });

        // ==================================================================
        // DEDICATED VIRTUAL ACCOUNT (funding channel, not source of truth)
        // ==================================================================
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('member_account_id')->unique()->constrained('member_accounts')->cascadeOnDelete();
            $table->string('account_number', 20)->unique();
            $table->string('account_name', 120);
            $table->string('bank_name', 80)->default('OurMonie');
            $table->string('provider', 30)->default('paystack');
            $table->string('provider_customer_code', 60)->nullable()->index();
            $table->string('provider_reference', 60)->nullable()->index();
            $table->string('status', 20)->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // TRANSACTIONS - header record for every money movement.
        // ==================================================================
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();      // wallet_funding|savings_deposit|savings_withdrawal|wallet_withdrawal|internal_transfer|interest|fee|reward_conversion|referral_reward|adjustment|reversal|refund|contribution
            $table->string('direction', 10);          // credit|debit
            $table->decimal('amount', 18, 2);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('pending')->index(); // pending|processing|completed|failed|reversed
            $table->string('channel', 30)->default('internal');        // internal|bank_transfer|paystack_card|direct_debit|admin
            $table->string('source', 30)->default('internal');         // internal|paystack|admin|system
            $table->foreignId('member_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('narration')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('provider_reference', 80)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['member_id', 'occurred_at']);
        });

        // ==================================================================
        // LEDGER ENTRIES - immutable double-entry lines.
        // sum(debits) must equal sum(credits) for every transaction.
        // ==================================================================
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('direction', 10);          // debit|credit
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('NGN');
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->string('memo')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ledger_account_id', 'created_at']);
            $table->index(['member_id', 'created_at']);
        });

        // ==================================================================
        // PAYSTACK WEBHOOK EVENTS - idempotency / duplicate protection.
        // ==================================================================
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->string('event_type', 80)->index();
            $table->string('provider', 30)->default('paystack');
            $table->string('reference', 80)->nullable()->index();
            $table->string('status', 20)->default('received'); // received|processed|ignored|failed|duplicate
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // RECONCILIATION - match internal ledger against provider rails.
        // ==================================================================
        Schema::create('reconciliation_records', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->index();
            $table->string('provider', 30)->default('paystack');
            $table->string('direction', 10);
            $table->decimal('provider_amount', 18, 2);
            $table->decimal('internal_amount', 18, 2)->nullable();
            $table->string('provider_status', 30)->nullable();
            $table->string('internal_status', 30)->nullable();
            $table->string('match_status', 20)->default('unmatched')->index(); // matched|unmatched|manual_review|resolved
            $table->text('discrepancy')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_records');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('member_accounts');
        Schema::dropIfExists('ledger_accounts');
    }
};
