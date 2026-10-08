<?php

namespace Database\Seeders;

use App\Models\LedgerAccount;
use App\Models\SavingsProduct;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Bootstraps the configurable business rules.
 *
 * Nothing here is duplicated as a hardcoded constant in application code:
 * rates, limits, fees, tiers and penalties are all editable rows.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedChartOfAccounts();
        $this->seedSettings();
        $this->seedSavingsProducts();
        $this->seedRewardConfig();
        $this->seedFees();
        $this->seedLimits();
        $this->seedTiers();
    }

    private function seedChartOfAccounts(): void
    {
        foreach (LedgerAccount::SYSTEM_CHART as $account) {
            LedgerAccount::updateOrCreate(
                ['code' => $account['code']],
                $account + ['is_system' => true, 'is_active' => true]
            );
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            // [group, key, value, type, label, description]
            ['general', 'platform_name', 'OurMonie', 'string', 'Platform Name', 'Displayed across the product.'],
            ['general', 'platform_tagline', 'Save • Grow • Together', 'string', 'Tagline', ''],
            ['general', 'support_email', 'support@ourmonie.ng', 'string', 'Support Email', ''],
            ['general', 'support_phone', '+234 800 000 0000', 'string', 'Support Phone', ''],
            ['onboarding', 'registration_open', '1', 'boolean', 'Registration Open', 'Accept new member registrations.'],
            ['onboarding', 'require_kyc_for_activation', '1', 'boolean', 'KYC Required', 'Membership activates only after KYC approval.'],
            ['contributions', 'min_contribution_amount', '3000', 'decimal', 'Minimum Contribution', 'Cooperative contribution floor in NGN.'],
            ['contributions', 'contribution_reminder_days', '3', 'integer', 'Reminder Lead Days', 'Days before due date to remind.'],
            ['wallet', 'funding_reference_prefix', 'OM', 'string', 'Funding Prefix', ''],
            ['limits', 'kyc0_max_wallet_funding', '50000', 'decimal', 'Unverified Funding Limit', 'Daily wallet funding cap before KYC.'],
            ['limits', 'kyc1_max_wallet_funding', '200000', 'decimal', 'KYC Level 1 Funding Limit', ''],
            ['limits', 'kyc2_max_wallet_funding', '5000000', 'decimal', 'KYC Level 2 Funding Limit', ''],
            ['security', 'max_login_attempts', '5', 'integer', 'Max Login Attempts', ''],
            ['security', 'lockout_minutes', '15', 'integer', 'Lockout Duration (minutes)', ''],
            ['rewards', 'points_to_ngn_rate', '0.1', 'decimal', 'Legacy Points Rate', 'Superseded by conversion tiers.'],
            ['referrals', 'referral_reward_points', '1000', 'decimal', 'Referral Reward Points', 'Points for a qualified referral.'],
            ['referrals', 'referral_requires_first_contribution', '1', 'boolean', 'Require First Contribution', 'Reward only after first qualifying contribution.'],
        ];

        foreach ($settings as [$group, $key, $value, $type, $label, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                compact('group', 'value', 'type', 'label', 'description') + ['is_public' => true]
            );
        }
    }

    private function seedSavingsProducts(): void
    {
        // SwiftSave - flexible, 3% per month (business requirement, configurable).
        SavingsProduct::updateOrCreate(
            ['code' => 'swiftsave'],
            [
                'name' => 'SwiftSave',
                'type' => SavingsProduct::TYPE_FLEXIBLE,
                'description' => 'Save flexibly, withdraw anytime, and earn interest every month.',
                'icon' => 'bolt',
                'interest_rate' => 3.0000,
                'interest_period' => 'monthly',
                'interest_payout' => 'monthly',
                'min_amount' => 1000,
                'max_amount' => 100000000,
                'min_balance' => 0,
                'early_withdrawal_allowed' => true,
                'penalty_type' => 'none',
                'penalty_value' => 0,
                'maturity_behaviour' => 'none',
                'min_kyc_level' => 1,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        // QuestSave - goal based.
        SavingsProduct::updateOrCreate(
            ['code' => 'questsave'],
            [
                'name' => 'QuestSave',
                'type' => SavingsProduct::TYPE_GOAL,
                'description' => 'Create a goal, set a target and date, then track your progress.',
                'icon' => 'target',
                'interest_rate' => 1.5000,
                'interest_period' => 'monthly',
                'interest_payout' => 'maturity',
                'min_amount' => 500,
                'max_amount' => 100000000,
                'min_balance' => 0,
                'early_withdrawal_allowed' => true,
                'penalty_type' => 'none',
                'penalty_value' => 0,
                'contribution_frequency' => 'monthly',
                'min_contribution' => 3000,
                'min_kyc_level' => 1,
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        // TimeVault - fixed term with configurable early-withdrawal penalty.
        SavingsProduct::updateOrCreate(
            ['code' => 'timevault'],
            [
                'name' => 'TimeVault',
                'type' => SavingsProduct::TYPE_FIXED,
                'description' => 'Lock your funds for a fixed term and earn a higher return at maturity.',
                'icon' => 'lock',
                'interest_rate' => 15.0000,
                'interest_period' => 'annually',
                'interest_payout' => 'maturity',
                'min_amount' => 50000,
                'max_amount' => 100000000,
                'min_balance' => 0,
                'allowed_terms' => [30, 90, 180, 365],
                'early_withdrawal_allowed' => true,
                'penalty_type' => 'percent',
                'penalty_value' => 5,
                'maturity_behaviour' => 'notify',
                'min_kyc_level' => 1,
                'is_active' => true,
                'sort_order' => 3,
            ]
        );
    }

    private function seedRewardConfig(): void
    {
        \App\Models\RewardRule::updateOrCreate(['code' => 'join'], [
            'name' => 'Joining Bonus',
            'activity' => 'join',
            'trigger_event' => 'membership_approved',
            'points' => 500,
            'points_type' => 'fixed',
            'is_active' => true,
        ]);

        \App\Models\RewardRule::updateOrCreate(['code' => 'monthly_contribution'], [
            'name' => 'Monthly Contribution',
            'activity' => 'monthly_contribution',
            'trigger_event' => 'contribution_completed',
            'points' => 200,
            'points_type' => 'fixed',
            'is_active' => true,
        ]);

        \App\Models\RewardRule::updateOrCreate(['code' => 'saving_percent'], [
            'name' => 'Saving Bonus',
            'activity' => 'saving',
            'trigger_event' => 'savings_deposit',
            'points' => 1,
            'points_type' => 'percent_of_amount',
            'max_points' => 5000,
            'is_active' => true,
        ]);

        \App\Models\RewardRule::updateOrCreate(['code' => 'referral'], [
            'name' => 'Successful Referral',
            'activity' => 'referral',
            'trigger_event' => 'referral_qualified',
            'points' => 1000,
            'points_type' => 'fixed',
            'is_active' => true,
        ]);

        // Conversion brackets - the formula lives here, never in code.
        // Rate above 10,000 points is 0.10 NGN/point up to 100,000, then 0.12.
        \App\Models\RewardConversionTier::updateOrCreate(
            ['min_points' => 10000],
            ['max_points' => 50000, 'cash_value' => 800, 'label' => 'Starter', 'is_active' => true]
        );
        \App\Models\RewardConversionTier::updateOrCreate(
            ['min_points' => 50000],
            ['max_points' => 100000, 'cash_value' => 5000, 'label' => 'Growth', 'is_active' => true]
        );
        \App\Models\RewardConversionTier::updateOrCreate(
            ['min_points' => 100000],
            ['max_points' => null, 'cash_value' => 12000, 'label' => 'Premium', 'is_active' => true]
        );
    }

    private function seedFees(): void
    {
        $fees = [
            ['code' => 'withdrawal_flat', 'name' => 'Withdrawal Fee', 'applies_to' => 'withdrawal', 'calculation' => 'flat', 'flat_amount' => 100, 'bearer_member' => true],
            ['code' => 'transfer_percent', 'name' => 'Transfer Fee', 'applies_to' => 'transfer', 'calculation' => 'percent', 'rate' => 1.0, 'min_amount' => 10, 'max_amount' => 2000, 'bearer_member' => true],
            ['code' => 'membership_fee', 'name' => 'Membership Fee', 'applies_to' => 'membership', 'calculation' => 'flat', 'flat_amount' => 1000, 'bearer_member' => true],
            ['code' => 'service_commission', 'name' => 'Service Commission', 'applies_to' => 'service_commission', 'calculation' => 'percent', 'rate' => 5.0, 'bearer_member' => false],
        ];

        foreach ($fees as $fee) {
            \App\Models\FeeConfig::updateOrCreate(['code' => $fee['code']], $fee + [
                'is_active' => true,
            ]);
        }
    }

    private function seedLimits(): void
    {
        $limits = [
            ['code' => 'wallet_funding_kyc0', 'transaction_type' => 'wallet_funding', 'scope_type' => 'kyc', 'scope_id' => 0, 'daily_limit' => 50000, 'monthly_limit' => 200000],
            ['code' => 'wallet_funding_kyc1', 'transaction_type' => 'wallet_funding', 'scope_type' => 'kyc', 'scope_id' => 1, 'daily_limit' => 200000, 'monthly_limit' => 2000000],
            ['code' => 'wallet_funding_kyc2', 'transaction_type' => 'wallet_funding', 'scope_type' => 'kyc', 'scope_id' => 2, 'daily_limit' => 5000000, 'monthly_limit' => 50000000],
            ['code' => 'wallet_withdrawal_kyc1', 'transaction_type' => 'wallet_withdrawal', 'scope_type' => 'kyc', 'scope_id' => 1, 'daily_limit' => 100000, 'monthly_limit' => 1000000],
            ['code' => 'wallet_withdrawal_kyc2', 'transaction_type' => 'wallet_withdrawal', 'scope_type' => 'kyc', 'scope_id' => 2, 'daily_limit' => 5000000, 'monthly_limit' => 50000000],
            ['code' => 'transfer_kyc1', 'transaction_type' => 'transfer', 'scope_type' => 'kyc', 'scope_id' => 1, 'daily_limit' => 100000, 'monthly_limit' => 1000000],
            ['code' => 'transfer_kyc2', 'transaction_type' => 'transfer', 'scope_type' => 'kyc', 'scope_id' => 2, 'daily_limit' => 5000000, 'monthly_limit' => 50000000],
            ['code' => 'savings_deposit_global', 'transaction_type' => 'savings_deposit', 'scope_type' => 'global', 'scope_id' => null, 'daily_limit' => 10000000, 'monthly_limit' => 100000000],
        ];

        foreach ($limits as $limit) {
            \App\Models\TransactionLimit::updateOrCreate(
                ['code' => $limit['code']],
                $limit + ['min_amount' => 0, 'is_active' => true]
            );
        }
    }

    private function seedTiers(): void
    {
        $tiers = [
            ['code' => 'bronze', 'name' => 'Bronze', 'min_threshold' => 0, 'max_threshold' => 99999, 'rewards_multiplier' => 1, 'is_default' => true,
                'benefits' => ['Standard savings access', 'Monthly reward earnings']],
            ['code' => 'silver', 'name' => 'Silver', 'min_threshold' => 100000, 'max_threshold' => 499999, 'rewards_multiplier' => 1.25, 'is_default' => false,
                'benefits' => ['Higher transaction limits', '1.25x reward points', 'Priority support']],
            ['code' => 'gold', 'name' => 'Gold', 'min_threshold' => 500000, 'max_threshold' => 1999999, 'rewards_multiplier' => 1.5, 'is_default' => false,
                'benefits' => ['1.5x reward points', 'Reduced withdrawal fees', 'Early access to new products']],
            ['code' => 'platinum', 'name' => 'Platinum', 'min_threshold' => 2000000, 'max_threshold' => null, 'rewards_multiplier' => 2, 'is_default' => false,
                'benefits' => ['2x reward points', 'Dedicated support', 'Custom savings arrangements']],
        ];

        $order = 0;
        foreach ($tiers as $tier) {
            \App\Models\MembershipTier::updateOrCreate(
                ['code' => $tier['code']],
                $tier + [
                    'max_wallet_funding' => null,
                    'max_transfer' => null,
                    'max_withdrawal' => null,
                    'sort_order' => $order++,
                    'is_active' => true,
                ]
            );
        }
    }
}
