<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\MemberNotification;
use App\Models\Referral;
use App\Models\RewardAccount;
use App\Models\RewardEntry;
use App\Models\SavingsGoal;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\WalletService;
use Illuminate\Database\Seeder;

/**
 * Demo member used to exercise the member dashboard end to end.
 *
 * Email:    member@ourmonie.test
 * Password: Password123!
 *
 * Every figure below is produced by real ledger postings - no balance is
 * ever written directly. Final position:
 *
 *   Wallet    125,480.00
 *   SwiftSave  55,000.00
 *   QuestSave  18,750.00
 *   TimeVault  25,000.00
 *   Savings    98,750.00
 *   Rewards    94,200 points (8,420 NGN convertible value)
 */
class DemoSeeder extends Seeder
{
    private const FUNDING = [60000, 45000, 35480, 43000, 70000];

    public function run(): void
    {
        $provisioner = app(AccountProvisioner::class);
        $wallet = app(WalletService::class);

        $user = User::updateOrCreate(
            ['email' => 'member@ourmonie.test'],
            [
                'name' => 'Adedayo Okafor',
                'phone' => '08031234567',
                'password' => 'Password123!',
                'is_admin' => false,
                'is_active' => true,
            ]
        );

        $tier = \App\Models\MembershipTier::where('code', 'gold')->first();

        $member = Member::updateOrCreate(
            ['user_id' => $user->id],
            [
                'membership_tier_id' => $tier?->id,
                'member_number' => AccountProvisioner::nextMemberNumber($user->id),
                'referral_code' => 'ADE24XY9',
                'membership_status' => 'active',
                'kyc_status' => 'verified',
                'kyc_level' => 2,
                'member_since' => now()->subMonths(9)->setDay(12),
                'approved_at' => now()->subMonths(9)->setDay(12),
                'gender' => 'male',
                'date_of_birth' => '1988-04-17',
                'address' => '14 Admiralty Way, Lekki Phase 1',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'bvn' => '22'.random_int(100000000, 999999999),
                'nin' => '1'.random_int(1000000000, 9999999999),
                'occupation' => 'Education',
                'employer' => 'Lagos State Ministry of Education',
                'job_title' => 'Senior Teacher',
                'employment_status' => 'full_time',
            ]
        );

        $provisioner->provisionFor($member);
        $member->refresh();

        // The goal must exist before deposits are routed to it.
        $goal = $this->seedGoal($member);

        if (! $member->transactions()->exists()) {
            $this->seedHistory($member, $wallet, $goal);
        }

        $this->seedRewards($member);
        $this->seedReferral($member);
        $this->seedNotifications($member);
        $this->seedAnnouncements();
    }

    /**
     * Reproduces a realistic transaction history through the real ledger.
     */
    private function seedHistory(Member $member, WalletService $wallet, ?SavingsGoal $goal): void
    {
        $vaultAccount = $member->accounts()->where('type', MemberAccount::TYPE_VAULT)->first();

        /*
         * Chronological script: [daysAgo, action, amount, fee]
         *
         * Sized so the real ledger lands on the prototype's headline figures
         * AND produces sensible month-over-month growth:
         *   wallet at last month end  100,000.00 -> 125,480.00
         *   savings at last month end  83,750.00 ->  98,750.00
         */
        $script = [
            [150, 'fund', 60000, 0],
            [140, 'fund', 45000, 0],
            [105, 'fund', 40000, 0],
            [97, 'swift', 40000, 0],
            [75, 'fund', 35480, 0],
            [70, 'goal', 18750, 0],
            [55, 'fund', 34520, 0],
            [50, 'vault', 25000, 0],
            [45, 'fee', 1000, 0],
            [35, 'withdraw', 15000, 150],
            [30, 'withdraw', 15000, 100],
            [15, 'fund', 38480, 0],
            [10, 'swift', 13000, 0],
            [5, 'interest', 2000, 0],
        ];

        foreach ($script as [$daysAgo, $action, $amount, $fee]) {
            if ($amount <= 0) {
                continue;
            }

            $occurredAt = now()->subDays($daysAgo)->setTime(10, 14);

            $transaction = match ($action) {
                'fund' => $this->attempt(fn () => $wallet->fundWallet($member, $amount, 'PSK'.strtoupper(bin2hex(random_bytes(4))))),
                'swift' => $this->attempt(fn () => $wallet->allocate($member, MemberAccount::TYPE_WALLET, MemberAccount::TYPE_SWIFT, $amount)),
                'goal' => $this->attempt(fn () => $goal !== null
                    ? $wallet->allocate($member, MemberAccount::TYPE_WALLET, MemberAccount::TYPE_QUEST, $amount, $goal)
                    : null),
                'vault' => $this->attempt(fn () => $vaultAccount !== null
                    ? $wallet->allocate($member, MemberAccount::TYPE_WALLET, MemberAccount::TYPE_VAULT, $amount)
                    : null),
                'withdraw' => $this->attempt(fn () => $wallet->withdraw($member, MemberAccount::TYPE_WALLET, $amount, $fee, 'ADEAYO OKAFOR - GTBank')),
                'fee' => $this->attempt(fn () => $wallet->chargeFee($member, $amount, 'Cooperative Fee', LedgerAccount::MEMBERSHIP_FEE_INCOME)),
                'interest' => $this->attempt(fn () => $wallet->creditInterest($member, MemberAccount::TYPE_SWIFT, $amount, 'Interest Earned')),
            };

            if ($transaction === null) {
                continue;
            }

            $transaction->forceFill(['occurred_at' => $occurredAt])->saveQuietly();
            $transaction->entries()->update(['created_at' => $occurredAt, 'updated_at' => $occurredAt]);

            // Collected funds settle from the provider float to our bank.
            if ($action === 'fund') {
                $settlement = $this->attempt(fn () => $wallet->settleFromProvider($amount));
                if ($settlement !== null) {
                    $settlement->forceFill(['occurred_at' => $occurredAt->copy()->addHours(2)])->saveQuietly();
                    $settlement->entries()->update(['created_at' => $occurredAt, 'updated_at' => $occurredAt]);
                }
            }
        }

        $this->seedGoalCompletion($goal);
    }

    private function attempt(callable $callback): ?\App\Models\Transaction
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            // Demo data must never break seeding.
            report($e);

            return null;
        }
    }

    /**
     * Sync the goal's running total from its backing savings account.
     *
     * The balance is re-read fresh here: a model captured before the
     * deposits still carries its original zero balance.
     */
    private function seedGoalCompletion(?SavingsGoal $goal): void
    {
        if ($goal === null || $goal->member_account_id === null) {
            return;
        }

        $balance = (float) MemberAccount::whereKey($goal->member_account_id)->value('balance');

        $goal->forceFill([
            'current_amount' => $balance,
            'status' => $balance >= (float) $goal->target_amount ? 'completed' : 'active',
            'completed_at' => $balance >= (float) $goal->target_amount ? now() : null,
        ])->save();
    }

    private function seedGoal(Member $member): ?SavingsGoal
    {
        $product = SavingsProduct::where('code', 'questsave')->first();
        if ($product === null) {
            return null;
        }

        $account = $member->accounts()->where('type', MemberAccount::TYPE_QUEST)->first();

        return SavingsGoal::updateOrCreate(
            ['member_id' => $member->id, 'name' => 'School Fees'],
            [
                'savings_product_id' => $product->id,
                'member_account_id' => $account?->id,
                'category' => 'Education',
                'target_amount' => 30000,
                'current_amount' => 0,
                'target_date' => now()->addMonths(6)->endOfMonth(),
                'interest_rate' => $product->interest_rate,
                'status' => 'active',
            ]
        );
    }

    /**
     * Two entries across different months so reward growth is real.
     *
     *   70,000 pts on day -70  -> NGN 6,000 convertible at last month end
     *   24,200 pts on day -12
     *   94,200 pts total      -> NGN 8,420 convertible today
     */
    private function seedRewards(Member $member): void
    {
        $account = RewardAccount::firstOrCreate(['member_id' => $member->id]);
        if ($account->lifetime_earned > 0) {
            return;
        }

        $entries = [
            [70000, 70, 'Welcome Season campaign - milestone bonus'],
            [24200, 12, 'Saving streak bonus'],
        ];

        $balance = 0;
        foreach ($entries as [$points, $daysAgo, $description]) {
            $balance += $points;

            RewardEntry::create([
                'reward_account_id' => $account->id,
                'member_id' => $member->id,
                'entry_type' => 'earn',
                'points' => $points,
                'balance_after' => $balance,
                'activity' => 'campaign',
                'description' => $description,
            ])->forceFill([
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ])->saveQuietly();
        }

        $account->update(['points_balance' => $balance, 'lifetime_earned' => $balance]);
    }

    private function seedReferral(Member $member): void
    {
        Referral::firstOrCreate(
            ['referrer_member_id' => $member->id, 'referee_user_id' => null],
            [
                'code' => $member->referral_code,
                'status' => 'clicked',
                'clicked_at' => now()->subDays(3),
            ]
        );
    }

    private function seedNotifications(Member $member): void
    {
        $items = [
            ['wallet_funding', 'Wallet Funded', 'Your wallet was funded with ₦20,000.00.', 'success'],
            ['interest', 'Interest Credited', 'Interest of ₦2,000.00 was credited to SwiftSave.', 'success'],
            ['contribution_reminder', 'Contribution Reminder', 'Your next cooperative contribution is due in 3 days.', 'info'],
            ['kyc', 'KYC Verified', 'Your identity has been verified. All features unlocked.', 'success'],
        ];

        foreach ($items as $i => [$type, $title, $body, $severity]) {
            MemberNotification::firstOrCreate(
                ['member_id' => $member->id, 'title' => $title],
                [
                    'type' => $type,
                    'body' => $body,
                    'channel' => 'in_app',
                    'severity' => $severity,
                    'is_read' => $i > 1,
                    'read_at' => $i > 1 ? now()->subHours($i) : null,
                    'sent_at' => now()->subHours($i + 2),
                ]
            );
        }
    }

    private function seedAnnouncements(): void
    {
        Announcement::firstOrCreate(
            ['title' => 'Welcome to OurMonie!'],
            [
                'body' => 'We are excited to have you as part of our growing cooperative. Keep saving, invite your friends and be part of the bigger vision.',
                'category' => 'announcement',
                'severity' => 'success',
                'audience' => 'all_members',
                'pinned' => true,
                'is_published' => true,
                'published_at' => now()->subDays(2),
            ]
        );

        Announcement::firstOrCreate(
            ['title' => 'TimeVault now supports 180-day terms'],
            [
                'body' => 'Lock your funds for a fixed term and earn a higher return at maturity. Every term and penalty is configurable from the admin portal.',
                'category' => 'news',
                'severity' => 'info',
                'audience' => 'all_members',
                'pinned' => false,
                'is_published' => true,
                'published_at' => now()->subDays(5),
            ]
        );
    }
}
