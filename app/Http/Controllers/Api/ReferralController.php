<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;
        $referrals = Referral::where('referrer_member_id', $member->id)->get();

        $byStatus = $referrals->countBy('status');

        return response()->json([
            'referral_code' => $member->referral_code,
            'referral_link' => rtrim(config('app.frontend_url'), '/')
                .'/register?ref='.$member->referral_code,
            'stats' => [
                'clicked' => (int) $byStatus->get(Referral::STATUS_CLICKED, 0),
                'registered' => (int) ($byStatus->get(Referral::STATUS_REGISTERED, 0)
                    + $byStatus->get(Referral::STATUS_KYC_PENDING, 0)
                    + $byStatus->get(Referral::STATUS_KYC_VERIFIED, 0)
                    + $byStatus->get(Referral::STATUS_FIRST_CONTRIBUTION_PENDING, 0)),
                'qualified' => (int) ($byStatus->get(Referral::STATUS_QUALIFIED, 0) + $byStatus->get(Referral::STATUS_REWARD_ISSUED, 0)),
                'total' => $referrals->count(),
                'total_points' => (int) $referrals->sum('reward_points'),
            ],
            'reward_rule' => [
                'points' => (int) \App\Models\Setting::getValue('referral_reward_points', 0),
                'requires_first_contribution' => (bool) \App\Models\Setting::getValue('referral_requires_first_contribution', true),
            ],
            'pipeline' => [
                ['status' => Referral::STATUS_CLICKED, 'label' => 'Clicked'],
                ['status' => Referral::STATUS_REGISTERED, 'label' => 'Registered'],
                ['status' => Referral::STATUS_KYC_PENDING, 'label' => 'KYC Pending'],
                ['status' => Referral::STATUS_KYC_VERIFIED, 'label' => 'KYC Verified'],
                ['status' => Referral::STATUS_FIRST_CONTRIBUTION_PENDING, 'label' => 'First Contribution Pending'],
                ['status' => Referral::STATUS_QUALIFIED, 'label' => 'Qualified'],
                ['status' => Referral::STATUS_REWARD_ISSUED, 'label' => 'Reward Issued'],
            ],
            'referrals' => $referrals->map(fn (Referral $r) => [
                'code' => $r->code,
                'name' => $r->refereeMember?->user?->name ?? 'Invited person',
                'status' => $r->status,
                'reward_points' => (int) $r->reward_points,
                'clicked_at' => optional($r->clicked_at)->toDateString(),
                'registered_at' => optional($r->registered_at)->toDateString(),
                'qualified_at' => optional($r->qualified_at)->toDateString(),
                // Age shown next to the invite - timestamp of the furthest
                // stage reached, not when the row happened to be written.
                'occurred_label' => optional(
                    $r->registered_at
                    ?? $r->kyc_verified_at
                    ?? $r->first_contribution_at
                    ?? $r->qualified_at
                    ?? $r->reward_issued_at
                    ?? $r->clicked_at
                    ?? $r->created_at
                )->diffForHumans(),
            ]),
        ]);
    }
}
