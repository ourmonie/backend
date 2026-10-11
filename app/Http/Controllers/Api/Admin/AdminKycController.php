<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\Auditor;
use App\Models\Setting;
use App\Services\DedicatedAccountService;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRD 18 - administrative KYC review. Hybrid model: officers see every open
 * submission and decide it; the outcome updates the member's KYC level and
 * membership state that PRD 19 (limits), PRD 14 (referral qualification) and
 * savings eligibility all key off.
 */
class AdminKycController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $query = KycRecord::query()
            ->with([
                'member:id,membership_tier_id,member_number,membership_status,kyc_status,kyc_level,created_at',
                'member.user:id,name,email',
                'member.tier:id,code,name',
                'reviewer:id,name',
            ])
            ->latest('id');

        $status = (string) $request->input('status', 'open');

        if ($status === 'open') {
            $query->whereIn('status', KycRecord::OPEN_STATUSES);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('member', fn ($m) => $m
                    ->where('member_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")));
            });
        }

        $records = $query->paginate(min(50, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($records->items())->map(fn (KycRecord $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'level' => (int) $r->level,
                'member' => [
                    'id' => $r->member?->id,
                    'member_number' => $r->member?->member_number,
                    'name' => $r->member?->user?->name,
                    'email' => $r->member?->user?->email,
                    'tier' => $r->member?->tier?->name,
                    'membership_status' => $r->member?->membership_status,
                    'kyc_level' => (int) ($r->member?->kyc_level ?? 0),
                ],
                'document_count' => count($r->documents ?? []),
                'submitted_at' => optional($r->submitted_at)->toDateTimeString(),
                'submitted_label' => optional($r->submitted_at)->diffForHumans(),
                'reviewed_at' => optional($r->reviewed_at)->toDateTimeString(),
                'reviewer' => $r->reviewer?->name,
                'rejection_reason' => $r->rejection_reason,
            ]),
            'summary' => [
                'open' => KycRecord::whereIn('status', KycRecord::OPEN_STATUSES)->count(),
                'by_status' => KycRecord::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ],
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $record = KycRecord::with([
            'member.user', 'member.tier', 'member.accounts', 'reviewer:id,name',
        ])->findOrFail($id);

        $member = $record->member;

        return response()->json([
            'record' => [
                'id' => $record->id,
                'status' => $record->status,
                'level' => (int) $record->level,
                'provider' => $record->provider,
                'submitted_at' => optional($record->submitted_at)->toDateTimeString(),
                'submitted_label' => optional($record->submitted_at)->diffForHumans(),
                'reviewed_at' => optional($record->reviewed_at)->toDateTimeString(),
                'reviewer' => $record->reviewer?->name,
                'rejection_reason' => $record->rejection_reason,
                'notes' => $record->notes,
                'data' => $record->data ?? [],
                'documents' => collect($record->documents ?? [])->map(fn (array $doc, int $index) => [
                    'index' => $index,
                    'field' => $doc['field'],
                    'label' => $doc['label'],
                    'original_name' => $doc['original_name'],
                    'mime' => $doc['mime'],
                    'size' => $doc['size'],
                    'uploaded_at' => $doc['uploaded_at'] ?? null,
                    'url' => route('admin.kyc.document', ['record' => $record->id, 'index' => $index]),
                ])->values(),
            ],
            'member' => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'membership_status' => $member->membership_status,
                'kyc_status' => $member->kyc_status,
                'kyc_level' => (int) $member->kyc_level,
                'tier' => $member->tier?->name,
                'member_since' => optional($member->member_since)->toDateString(),
                'profile' => [
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'phone' => $member->user?->phone,
                    'gender' => $member->gender,
                    'date_of_birth' => optional($member->date_of_birth)->toDateString(),
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
                'accounts' => $member->accounts->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'balance' => (float) $a->balance,
                ]),
            ],
        ]);
    }

    /**
     * Stream a submitted document. Files live on the private disk and are only
     * reachable through a permission-checked route, never a public URL.
     */
    public function document(Request $request, int $record, int $index): StreamedResponse|JsonResponse
    {
        $kyc = KycRecord::findOrFail($record);
        $documents = $kyc->documents ?? [];

        if (! isset($documents[$index])) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $path = $documents[$index]['path'] ?? null;

        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return response()->json(['message' => 'Document file is no longer available.'], 404);
        }

        return Storage::disk(self::DISK)->response(
            $path,
            $documents[$index]['original_name'] ?? 'document',
        );
    }

    /**
     * Approve, reject or request corrections on a submission.
     */
    public function decide(Request $request, int $id): JsonResponse
    {
        $record = KycRecord::with(['member.user', 'member.tier'])->findOrFail($id);

        if (! in_array($record->status, KycRecord::OPEN_STATUSES, true)) {
            return response()->json([
                'message' => 'This application has already been decided.',
                'status' => $record->status,
            ], 409);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'correct'])],
            'level' => ['required_if:decision,approve', 'integer', 'between:1,3'],
            'reason' => ['required_if:decision,reject', 'required_if:decision,correct', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = $record->member;
        $actor = $request->user();

        [$recordStatus, $memberStatus, $severity] = match ($validated['decision']) {
            'approve' => [KycRecord::STATUS_VERIFIED, 'verified', 'success'],
            'reject' => [KycRecord::STATUS_REJECTED, 'failed', 'error'],
            'correct' => [KycRecord::STATUS_RESUBMISSION_REQUIRED, 'resubmission_required', 'warning'],
        };

        $reason = $validated['reason'] ?? null;

        $title = match ($recordStatus) {
            KycRecord::STATUS_VERIFIED => 'KYC verified',
            KycRecord::STATUS_REJECTED => 'KYC could not be verified',
            default => 'KYC needs corrections',
        };

        $body = match ($recordStatus) {
            KycRecord::STATUS_VERIFIED => 'Your identity has been verified. Your account limits now reflect your verified status.',
            KycRecord::STATUS_REJECTED => 'We could not verify your identity: '.$reason.' You may submit a corrected application.',
            default => 'Your application needs corrections: '.$reason.' Please update your details and resubmit.',
        };

        DB::transaction(function () use ($record, $member, $validated, $recordStatus, $memberStatus, $actor, $title, $body, $severity) {
            $record->update([
                'status' => $recordStatus,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'rejection_reason' => $validated['reason'] ?? null,
                'notes' => $validated['notes'] ?? $record->notes,
                'processed_at' => $record->processed_at ?? now(),
            ]);

            $member->kyc_status = $memberStatus;

            if ($recordStatus === KycRecord::STATUS_VERIFIED) {
                $approvedLevel = (int) ($validated['level'] ?? 1);
                $member->kyc_level = max((int) $member->kyc_level, $approvedLevel);
                $member->member_since = $member->member_since ?? now();
                $member->approved_at = $member->approved_at ?? now();
                $member->approved_by = $member->approved_by ?? $actor->id;

                // PRD 2 - an approved KYC is what makes a member bonafide.
                if ((bool) Setting::getValue('require_kyc_for_activation', false)
                    && $member->membership_status === 'pending') {
                    $member->membership_status = 'active';
                }
            }

            $member->save();

            Notifier::notify($member, 'kyc.'.$recordStatus, $title, $body, $severity, '/app/kyc', [
                'level' => (int) $member->kyc_level,
            ]);
        });

        Auditor::record(
            'kyc.'.$validated['decision'],
            'kyc',
            'KYC application #'.$record->id.' '.$validated['decision'].'ed for '.$member->member_number,
            ['status' => KycRecord::STATUS_SUBMITTED, 'kyc_status' => $member->kyc_status],
            ['status' => $recordStatus, 'kyc_status' => $member->kyc_status, 'kyc_level' => (int) $member->kyc_level],
            $record,
        );

        // PRD 87-91 - a verified member is entitled to a dedicated account
        // number. Issued after the decision commits, never inside it: the
        // provider call must not hold the review transaction open, and a
        // slow bank must not roll back an approval that already happened.
        if ($recordStatus === KycRecord::STATUS_VERIFIED) {
            $this->issueDedicatedAccount($member);
        }

        return response()->json([
            'message' => match ($validated['decision']) {
                'approve' => 'KYC approved.',
                'reject' => 'KYC rejected. The member has been notified.',
                default => 'Corrections requested. The member has been notified.',
            },
            'status' => $record->fresh()->status,
            'member' => [
                'kyc_status' => $member->fresh()->kyc_status,
                'kyc_level' => (int) $member->fresh()->kyc_level,
            ],
        ]);
    }

    /**
     * Best effort by design: if no gateway is active, or the provider is
     * slow, the approval still stands and the number is issued on the next
     * wallet load instead. Never surfaces a provider error to the reviewer.
     */
    private function issueDedicatedAccount(\App\Models\Member $member): void
    {
        try {
            app(DedicatedAccountService::class)->assign($member);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
