<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==================================================================
        // SAVINGS PRODUCTS - every business rule lives here, not in code.
        // ==================================================================
        Schema::create('savings_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('type', 30)->index();      // flexible|goal|fixed
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();

            // Interest
            $table->decimal('interest_rate', 8, 4)->default(0);        // 3.0000
            $table->string('interest_period', 20)->default('monthly'); // monthly|annually|maturity
            $table->string('interest_payout', 20)->default('monthly'); // monthly|maturity|daily

            // Amount rules
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->decimal('min_balance', 18, 2)->default(0);

            // Term rules
            $table->json('allowed_terms')->nullable();                 // [30,90,180,365]
            $table->boolean('early_withdrawal_allowed')->default(false);
            $table->string('penalty_type', 20)->default('percent');    // percent|flat|forfeit
            $table->decimal('penalty_value', 18, 2)->default(0);
            $table->string('maturity_behaviour', 30)->default('auto_renew'); // auto_renew|hold|payout|notify

            // Contribution rules
            $table->string('contribution_frequency', 20)->nullable();   // daily|weekly|monthly
            $table->decimal('min_contribution', 18, 2)->nullable();

            // Eligibility & activation
            $table->json('eligibility')->nullable();
            $table->unsignedTinyInteger('min_kyc_level')->default(0);
            $table->json('allowed_tiers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // QUESTSAVE GOALS
        // ==================================================================
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_product_id')->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('member_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->decimal('target_amount', 18, 2);
            $table->decimal('current_amount', 18, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->boolean('auto_save')->default(false);
            $table->decimal('auto_save_amount', 18, 2)->nullable();
            $table->string('auto_save_frequency', 20)->nullable();
            $table->boolean('allow_external_contributions')->default(false);
            $table->string('status', 20)->default('active')->index(); // active|completed|paused|cancelled|withdrawn
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });

        // ==================================================================
        // TIMEVAULT FIXED DEPOSITS
        // ==================================================================
        Schema::create('savings_vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_product_id')->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('member_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('vault_number', 30)->unique();
            $table->decimal('principal', 18, 2);
            $table->unsignedInteger('term_days');
            $table->decimal('interest_rate', 8, 4);
            $table->string('interest_period', 20)->default('maturity');
            $table->decimal('expected_return', 18, 2)->default(0);
            $table->decimal('interest_earned', 18, 2)->default(0);
            $table->date('starts_on');
            $table->date('matures_on')->index();
            $table->string('status', 20)->default('locked')->index(); // locked|matured|withdrawn|early_withdrawn|cancelled
            $table->boolean('auto_renew')->default(false);
            $table->decimal('penalty_applied', 18, 2)->default(0);
            $table->timestamp('matured_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ==================================================================
        // AUTOMATED / RECURRING CONTRIBUTIONS
        // ==================================================================
        Schema::create('contribution_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30)->default('wallet'); // wallet|external
            $table->foreignId('source_account_id')->nullable()->constrained('member_accounts')->nullOnDelete();
            $table->string('destination_type', 30);              // swift_save|quest_save|time_vault|cooperative
            $table->foreignId('savings_goal_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->foreignId('savings_product_id')->nullable()->constrained('savings_products')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('frequency', 20)->default('monthly');  // daily|weekly|monthly
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_on')->nullable()->index();
            $table->string('status', 20)->default('active')->index(); // active|paused|completed|failed|cancelled
            $table->unsignedInteger('missed_count')->default(0);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('contribution_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('scheduled_on');
            $table->string('status', 20)->default('pending'); // pending|paid|missed|failed
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_records');
        Schema::dropIfExists('contribution_plans');
        Schema::dropIfExists('savings_vaults');
        Schema::dropIfExists('savings_goals');
        Schema::dropIfExists('savings_products');
    }
};
