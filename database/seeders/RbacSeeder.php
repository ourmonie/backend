<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * PRD 31 - administrative roles and a granular, individually configurable
 * permission catalogue. PRD 32 lists the capabilities each role needs; PRD 31
 * gives a worked example (a KYC Officer can review KYC but must not be able to
 * change balances, transactions or interest rates) which the assignments below
 * encode.
 *
 * Nothing here is enforced by hardcoded conditionals - routes name a
 * permission code and the role/permission rows decide who satisfies it.
 */
class RbacSeeder extends Seeder
{
    /**
     * [code, name, group, description]
     *
     * @var array<int, array{0:string,1:string,2:string,3:string}>
     */
    public const PERMISSIONS = [
        ['dashboard.view', 'View admin dashboard', 'dashboard', 'Read the administrative dashboard and headline metrics.'],
        ['member.view', 'View member records', 'member', 'Read member profiles, accounts and membership state.'],
        ['member.manage', 'Approve and manage members', 'member', 'Approve memberships, suspend, reactivate and close members.'],
        ['kyc.view', 'View KYC information', 'kyc', 'Read KYC submissions and supporting documents.'],
        ['kyc.review', 'Review and decide KYC', 'kyc', 'Approve, reject or request corrections on KYC submissions.'],
        ['wallet.view', 'View balances and wallets', 'finance', 'Read member wallets, balances and account positions.'],
        ['wallet.hold', 'Place and release holds', 'finance', 'Suspend, restrict or release member accounts.'],
        ['wallet.adjust', 'Request manual adjustments', 'finance', 'Create a maker-checker adjustment request.'],
        ['adjust.approve', 'Approve adjustments', 'finance', 'Approve or reject pending financial adjustments (checker).'],
        ['transaction.view', 'Review transactions', 'finance', 'Inspect member transactions and their ledger entries.'],
        ['withdrawal.review', 'Review withdrawals', 'finance', 'Review and approve withdrawals requiring authorisation.'],
        ['reconciliation.view', 'Review reconciliation', 'finance', 'See payment reconciliation and discrepancies.'],
        ['settings.view', 'View configuration', 'settings', 'Read system configuration, products, tiers, fees and limits.'],
        ['settings.general', 'Manage general configuration', 'settings', 'Edit general platform settings.'],
        ['settings.product.manage', 'Configure savings products', 'settings', 'Configure products, interest rates, penalties and maturity.'],
        ['settings.tier.manage', 'Configure membership tiers', 'settings', 'Create and edit membership tiers and thresholds.'],
        ['settings.fee.manage', 'Manage fees and commissions', 'settings', 'Edit fee configs and service commissions.'],
        ['settings.limit.manage', 'Configure transaction limits', 'settings', 'Edit KYC/tier scoped transaction limits.'],
        ['settings.reward.manage', 'Configure rewards', 'settings', 'Edit reward rules, conversion tiers and referral rewards.'],
        ['settings.contribution.manage', 'Configure contributions', 'settings', 'Edit contribution rules and reminders.'],
        ['announcement.manage', 'Publish notices', 'content', 'Create, publish and archive announcements.'],
        ['faq.manage', 'Manage FAQ and support content', 'content', 'Edit FAQs and support information.'],
        ['report.view', 'Review reports', 'report', 'View operational and financial reports.'],
        ['report.export', 'Export reports', 'report', 'Export reports to file.'],
        ['audit.view', 'Review audit logs', 'audit', 'Read the audit trail.'],
        ['role.manage', 'Manage roles and permissions', 'admin', 'Create roles and assign permissions.'],
        ['support.manage', 'Manage support requests', 'admin', 'Triage and resolve member support requests.'],
    ];

    /**
     * PRD 31 role catalogue -> permission codes.
     *
     * @var array<string, array{code:string,name:string,description:string,permissions:array<int,string>}>
     */
    public const ROLES = [
        'super_admin' => [
            'name' => 'Super Admin',
            'description' => 'Full platform control, including role and permission management.',
            'permissions' => '*', // every permission, including ones added later
        ],
        'operations' => [
            'name' => 'Operations',
            'description' => 'Day-to-day member operations, membership approvals and notices.',
            'permissions' => [
                'dashboard.view', 'member.view', 'member.manage', 'kyc.view',
                'transaction.view', 'withdrawal.review', 'announcement.manage',
                'settings.contribution.manage', 'support.manage', 'report.view',
            ],
        ],
        'finance' => [
            'name' => 'Finance',
            'description' => 'Money movement, fees, reconciliation and adjustment approval.',
            'permissions' => [
                'dashboard.view', 'wallet.view', 'transaction.view', 'withdrawal.review',
                'reconciliation.view', 'adjust.approve', 'settings.fee.manage',
                'settings.limit.manage', 'report.view', 'report.export', 'audit.view',
            ],
        ],
        'kyc_officer' => [
            'name' => 'KYC Officer',
            'description' => 'Reviews identity verification only. Cannot touch balances, transactions or rates.',
            'permissions' => [
                'dashboard.view', 'member.view', 'kyc.view', 'kyc.review', 'report.view',
            ],
        ],
        'customer_support' => [
            'name' => 'Customer Support',
            'description' => 'Answers member questions and handles account issue reports.',
            'permissions' => [
                'dashboard.view', 'member.view', 'kyc.view', 'transaction.view',
                'support.manage', 'faq.manage',
            ],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access to everything financial plus the audit trail.',
            'permissions' => [
                'dashboard.view', 'member.view', 'kyc.view', 'wallet.view',
                'transaction.view', 'reconciliation.view', 'audit.view',
                'settings.view', 'report.view', 'report.export',
            ],
        ],
        'coop_admin' => [
            'name' => 'Cooperative Administrator',
            'description' => 'Runs the cooperative: members, KYC, configuration and content.',
            'permissions' => [
                'dashboard.view', 'member.view', 'member.manage', 'kyc.view', 'kyc.review',
                'wallet.view', 'wallet.hold', 'wallet.adjust', 'transaction.view',
                'withdrawal.review', 'reconciliation.view',
                'settings.view', 'settings.general', 'settings.product.manage',
                'settings.tier.manage', 'settings.fee.manage', 'settings.limit.manage',
                'settings.reward.manage', 'settings.contribution.manage',
                'announcement.manage', 'faq.manage', 'support.manage',
                'report.view', 'report.export', 'audit.view',
            ],
        ],
    ];

    public function run(): void
    {
        $permissionIds = [];

        foreach (self::PERMISSIONS as [$code, $name, $group, $description]) {
            $permission = Permission::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'group' => $group, 'description' => $description],
            );
            $permissionIds[$code] = $permission->id;
        }

        foreach (self::ROLES as $code => $definition) {
            $role = Role::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => $code === Role::SUPER_ADMIN,
                ],
            );

            $granted = $definition['permissions'] === '*'
                ? array_values($permissionIds)
                : array_values(array_intersect_key(
                    $permissionIds,
                    array_flip($definition['permissions']),
                ));

            $role->permissions()->sync($granted);
        }
    }
}
