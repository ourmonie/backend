<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountHold;
use App\Models\Member;
use App\Services\Auditor;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PRD 32 - member management, PRD 2 - membership approval, PRD 20 - account
 * holds and restrictions. Every mutation here writes an audit record and, where
 * the member is affected, a notification.
 */
class AdminMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Member::query()
            ->with(['user:id,name,email,phone,last_login_at', 'tier:id,code,name', 'accounts:id,member_id,type,balance'])
            ->withCount(['activeHolds', 'kycRecords'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('membership_status', $request->input('status'));
        }

        if ($request->filled('kyc')) {
            $query->where('kyc_status', $request->input('kyc'));
        }

        if ($request->filled('tier')) {
            $query->whereHas('tier', fn ($t) => $t->where('code', $request->input('tier')));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('member_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $members = $query->paginate(min(50, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($members->items())->map(fn (Member $m) => $this->listPayload($m)),
            'summary' => [
                'total' => Member::count(),
                'active' => Member::where('membership_status', 'active')->count(),
                'pending' => Member::where('membership_status', 'pending')->count(),
                'suspended' => Member::where('membership_status', 'suspended')->count(),
                'verified' => Member::where('kyc_status', 'verified')->count(),
            ],
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $member = Member::with([
            'user:id,name,email,phone,last_login_at',
            'tier',
            'accounts:id,member_id,type,balance,account_number',
            'wallet.account',
            'rewardAccount',
            'goals',
            'vaults',
            'referrer.user:id,name',
        ])->findOrFail($id);

        return response()->json([
            'member' => array_merge($this->listPayload($member, true), [
                'profile' => [
                    'gender' => $member->gender,
                    'date_of_birth' => optional($member->date_of_birth)->toDateString(),
                    'avatar' => $member->avatar,
                    'address' => $member->address,
                    'city' => $member->city,
                    'state' => $member->state,
                    'occupation' => $member->occupation,
                    'employer' => $member->employer,
                    'job_title' => $member->job_title,
                    'employment_status' => $member->employment_status,
                    'bvn' => $member->bvn,
                    'nin' => $member->nin,
                ],
                'referral' => [
                    'code' => $member->referral_code,
                    'referred_by' => $member->referrer?->user?->name,
                    'referrals_made' => Member::where('referred_by', $member->id)->count(),
                ],
                'approval' => [
                    'member_since' => optional($member->member_since)->toDateString(),
                    'approved_at' => optional($member->approved_at)->toDateTimeString(),
                ],
                'accounts' => $member->accounts->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'balance' => (float) $a->balance,
                    'account_number' => $a->account_number,
                    'status' => $a->status,
                ]),
                'holds' => $member->holds()->with('placedBy:id,name', 'releasedBy:id,name')
                    ->latest()->limit(50)->get()
                    ->map(fn (AccountHold $h) => $this->holdPayload($h)),
                'kyc_history' => $member->kycRecords()->with('reviewer:id,name')->latest()->limit(5)
                    ->get()
                    ->map(fn ($r) => [
                        'id' => $r->id,
                        'status' => $r->status,
                        'level' => (int) $r->level,
                        'submitted_at' => optional($r->submitted_at)->toDateTimeString(),
                        'reviewed_at' => optional($r->reviewed_at)->toDateTimeString(),
                        'reviewer' => $r->reviewer?->name,
                        'rejection_reason' => $r->rejection_reason,
                    ]),
                'transactions' => $member->transactions()->latest()->limit(20)->get()
                    ->map(fn ($t) => [
                        'id' => $t->id,
                        'reference' => $t->reference,
                        'type' => $t->type,
                        'status' => $t->status,
                        'amount' => (float) $t->amount,
                        'fee' => (float) ($t->fee ?? 0),
                        'created_label' => $t->created_at->diffForHumans(),
                        'created_at' => $t->created_at->toDateTimeString(),
                    ]),
                'goals' => $member->goals->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'category' => $g->category,
                    'product' => $g->product?->name,
                    'target_amount' => (float) $g->target_amount,
                    'balance' => (float) $g->current_amount,
                    'progress' => $g->progressPercent(),
                    'status' => $g->status,
                ]),
                'vaults' => $member->vaults->map(fn ($v) => [
                    'id' => $v->id,
                    'vault_number' => $v->vault_number,
                    'product' => $v->product?->name,
                    'principal' => (float) $v->principal,
                    'term_days' => (int) $v->term_days,
                    'interest_rate' => (float) $v->interest_rate,
                    'expected_return' => (float) $v->expected_return,
                    'interest_earned' => (float) $v->interest_earned,
                    'starts_on' => optional($v->starts_on)->toDateString(),
                    'matures_on' => optional($v->matures_on)->toDateString(),
                    'status' => $v->status,
                ]),
            ]),
        ]);
    }

    /**
     * Approve, suspend, reactivate or close a membership (PRD 32).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $member = Member::with('user')->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'active', 'suspended', 'closed'])],
            'reason' => ['required_if:status,suspended', 'required_if:status,closed', 'nullable', 'string', 'max:500'],
        ]);

        $previous = $member->membership_status;

        if ($previous === $validated['status']) {
            return response()->json([
                'message' => 'Membership status is already "'.$validated['status'].'".',
                'status' => $previous,
            ], 409);
        }

        DB::transaction(function () use ($member, $validated, $request) {
            $member->membership_status = $validated['status'];

            if ($validated['status'] === 'active') {
                $member->member_since = $member->member_since ?? now();
                $member->approved_at = $member->approved_at ?? now();
                $member->approved_by = $member->approved_by ?? $request->user()->id;
            }

            $member->save();

            Notifier::notify(
                $member,
                'membership.'.$validated['status'],
                'Membership '.ucfirst($validated['status']),
                $validated['status'] === 'active'
                    ? 'Your membership has been approved. Welcome to the cooperative.'
                    : 'Your membership status is now '.$validated['status'].'.'
                      .(isset($validated['reason']) ? ' Reason: '.$validated['reason'] : ''),
                $validated['status'] === 'active' ? 'success' : 'warning',
                '/app/profile',
            );
        });

        Auditor::record(
            'membership.status_changed',
            'member',
            'Membership of '.$member->member_number.' changed',
            ['membership_status' => $previous],
            ['membership_status' => $validated['status'], 'reason' => $validated['reason'] ?? null],
            $member,
        );

        return response()->json([
            'message' => 'Membership status updated.',
            'status' => $member->fresh()->membership_status,
        ]);
    }

    /**
     * PRD 32 - administrative correction of a member's own record details.
     * Financial state is never touched here: this edits identity fields only.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $member = Member::with('user')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($member->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ]);

        $before = [
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'phone' => $member->user?->phone,
            'occupation' => $member->occupation,
            'employer' => $member->employer,
            'job_title' => $member->job_title,
            'employment_status' => $member->employment_status,
        ];

        DB::transaction(function () use ($member, $validated) {
            $userFields = array_intersect_key($validated, array_flip(['name', 'email', 'phone']));
            if ($userFields !== [] && $member->user !== null) {
                $member->user->forceFill($userFields)->save();
            }

            $memberFields = array_intersect_key($validated, array_flip([
                'occupation', 'employer', 'job_title', 'employment_status',
            ]));
            if ($memberFields !== []) {
                $member->forceFill($memberFields)->save();
            }
        });

        Auditor::record(
            'member.updated',
            'member',
            'Record of '.$member->member_number.' updated',
            $before,
            array_merge($before, $validated),
            $member,
        );

        return response()->json([
            'message' => 'Member record updated.',
            'member' => $this->listPayload($member->fresh(['user', 'tier', 'accounts'])),
        ]);
    }

    /**
     * PRD 32 - remove a member. A member whose accounts still hold money
     * cannot be deleted: the ledger belongs to them, and destroying the
     * owner of a live balance would orphan the cooperative's own books.
     *
     * The deletion is a soft removal plus a lockout. The member row and
     * every ledger entry pointing at it are deliberately kept (PRD 23/45 -
     * the ledger must never be orphaned); the user simply can no longer
     * sign in.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = Member::with('user')->findOrFail($id);

        $balance = (float) $member->accounts()->sum('balance');

        if ($balance > 0) {
            return response()->json([
                'message' => sprintf(
                    'Cannot delete %s: ₦%s is still held across their accounts. Sweep the balances to zero first.',
                    $member->member_number,
                    number_format($balance, 2)
                ),
            ], 422);
        }

        DB::transaction(function () use ($member) {
            $member->delete();

            if ($member->user !== null) {
                $member->user->forceFill(['is_active' => false])->save();
                $member->user->tokens()->delete();
            }
        });

        Auditor::record(
            'member.deleted',
            'member',
            'Member '.$member->member_number.' ('.($member->user?->name ?? 'no name').') deleted',
            ['membership_status' => $member->membership_status],
            ['deleted' => true],
            null,
        );

        return response()->json([
            'message' => 'Member deleted.',
        ]);
    }

    /**
     * PRD 32 - bulk removal. Each member is judged on its own merits, so one
     * member with a live balance never blocks the rest; the response reports
     * exactly what happened to each.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:members,id'],
        ]);

        $deleted = 0;
        $skipped = [];

        foreach ($validated['ids'] as $id) {
            $member = Member::with('user')->find($id);
            if ($member === null || $member->trashed()) {
                continue;
            }

            $balance = (float) $member->accounts()->sum('balance');

            if ($balance > 0) {
                $skipped[] = [
                    'member_number' => $member->member_number,
                    'name' => $member->user?->name,
                    'reason' => sprintf('₦%s still held', number_format($balance, 2)),
                ];

                continue;
            }

            DB::transaction(function () use ($member) {
                $member->delete();

                if ($member->user !== null) {
                    $member->user->forceFill(['is_active' => false])->save();
                    $member->user->tokens()->delete();
                }
            });

            Auditor::record(
                'member.deleted',
                'member',
                'Member '.$member->member_number.' deleted in bulk',
                ['membership_status' => $member->membership_status],
                ['deleted' => true],
                null,
            );

            $deleted++;
        }

        return response()->json([
            'message' => sprintf(
                '%d member(s) deleted%s.',
                $deleted,
                $skipped !== [] ? ', '.count($skipped).' skipped' : ''
            ),
            'deleted' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * PRD 20 - place a hold or restriction on a member account.
     */
    public function storeHold(Request $request, int $id): JsonResponse
    {
        $member = Member::with('user')->findOrFail($id);

        $validated = $request->validate([
            'hold_type' => ['required', Rule::in(AccountHold::TYPES)],
            'reason_code' => ['nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $existing = $member->activeHolds()
            ->where('hold_type', $validated['hold_type'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This restriction is already active on the account.',
                'hold' => $this->holdPayload($existing),
            ], 409);
        }

        $hold = DB::transaction(fn () => AccountHold::create([
            'member_id' => $member->id,
            'hold_type' => $validated['hold_type'],
            'reason_code' => $validated['reason_code'] ?? null,
            'reason' => $validated['reason'],
            'is_active' => true,
            'placed_by' => $request->user()->id,
            'starts_at' => now(),
            'expires_at' => $validated['expires_at'] ?? null,
        ]));

        Notifier::notify(
            $member,
            'account.restricted',
            'Account restriction applied',
            'A restriction has been applied to your account: '.$validated['reason'].' Please contact support if you believe this is an error.',
            'error',
            '/app/wallet',
            ['hold_type' => $validated['hold_type']],
        );

        Auditor::record(
            'account.hold_placed',
            'account',
            'Hold "'.$validated['hold_type'].'" placed on '.$member->member_number,
            [],
            ['hold_type' => $validated['hold_type'], 'reason' => $validated['reason'], 'expires_at' => $validated['expires_at'] ?? null],
            $member,
        );

        return response()->json([
            'message' => 'Restriction applied.',
            'hold' => $this->holdPayload($hold),
        ], 201);
    }

    /**
     * PRD 20 - release a hold. Released and already-expired holds report back
     * cleanly rather than silently succeeding twice.
     */
    public function releaseHold(Request $request, int $id, int $holdId): JsonResponse
    {
        $member = Member::findOrFail($id);

        $hold = $member->holds()->findOrFail($holdId);

        if (! $hold->is_active) {
            return response()->json([
                'message' => 'This restriction has already been released.',
                'hold' => $this->holdPayload($hold),
            ], 409);
        }

        $validated = $request->validate([
            'release_note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($hold, $validated, $request) {
            $hold->update([
                'is_active' => false,
                'released_at' => now(),
                'released_by' => $request->user()->id,
                'release_note' => $validated['release_note'] ?? null,
            ]);
        });

        Notifier::notify(
            $member,
            'account.released',
            'Account restriction lifted',
            'The restriction on your account has been lifted.'
              .(isset($validated['release_note']) ? ' Note: '.$validated['release_note'] : ''),
            'success',
            '/app/wallet',
        );

        Auditor::record(
            'account.hold_released',
            'account',
            'Hold "'.$hold->hold_type.'" released on '.$member->member_number,
            ['is_active' => true],
            ['is_active' => false, 'release_note' => $validated['release_note'] ?? null],
            $member,
        );

        return response()->json([
            'message' => 'Restriction released.',
            'hold' => $this->holdPayload($hold->fresh()),
        ]);
    }

    /* ------------------------------- helpers ------------------------------- */

    private function listPayload(Member $m, bool $full = false): array
    {
        $m->loadMissing('user:id,name,email,phone', 'tier:id,code,name', 'accounts');

        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'member_number' => $m->member_number,
            'name' => $m->user?->name,
            'email' => $m->user?->email,
            'phone' => $m->user?->phone,
            'tier' => $m->tier?->name,
            'tier_code' => $m->tier?->code,
            'membership_status' => $m->membership_status,
            'kyc_status' => $m->kyc_status,
            'kyc_level' => (int) $m->kyc_level,
            'balance' => round((float) $m->accounts->sum('balance'), 2),
            'account_count' => $m->accounts->count(),
            'active_holds' => $m->active_holds_count ?? $m->activeHolds()->count(),
            'member_since' => optional($m->member_since)->toDateString(),
            'joined_label' => optional($m->created_at)->diffForHumans(),
            'created_at' => $m->created_at->toDateTimeString(),
            'last_login_at' => optional($m->user?->last_login_at)->toDateTimeString(),
        ];
    }

    private function holdPayload(AccountHold $h): array
    {
        $expired = $h->expires_at !== null && $h->expires_at->isPast();

        return [
            'id' => $h->id,
            'hold_type' => $h->hold_type,
            'reason_code' => $h->reason_code,
            'reason' => $h->reason,
            'is_active' => (bool) $h->is_active && ! $expired,
            'expired' => $expired,
            'starts_at' => optional($h->starts_at)->toDateTimeString(),
            'expires_at' => optional($h->expires_at)->toDateTimeString(),
            'placed_by' => $h->placedBy?->name,
            'released_at' => optional($h->released_at)->toDateTimeString(),
            'released_by' => $h->releasedBy?->name,
            'release_note' => $h->release_note,
            'created_label' => $h->created_at->diffForHumans(),
        ];
    }
}
