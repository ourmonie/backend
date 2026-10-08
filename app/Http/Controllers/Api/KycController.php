<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PRD 18 - member KYC submission.
 *
 * Hybrid model: this endpoint collects identity data and documents, then hands
 * the record to an administrator for review. Automated provider verification
 * can be layered in later by switching `kyc_provider` and hooking the
 * `processing` state; nothing here assumes a provider exists.
 */
class KycController extends Controller
{
    private const DOCUMENT_FIELDS = [
        'government_id' => 'Government identification',
        'photograph' => 'Passport / photograph',
        'proof_of_address' => 'Proof of residential address',
    ];

    private const DISK = 'local';

    /** Current KYC position for the member. */
    public function show(Request $request): JsonResponse
    {
        $member = $request->user()->member()->with(['kycRecords' => fn ($q) => $q->latest()->limit(5)])->firstOrFail();

        $latest = $member->kycRecords->first();

        return response()->json([
            'status' => $member->kyc_status,
            'level' => (int) $member->kyc_level,
            'record' => $latest ? $this->recordPayload($latest) : null,
            'profile' => [
                'bvn' => $member->bvn,
                'nin' => $member->nin,
                'date_of_birth' => optional($member->date_of_birth)->toDateString(),
                'address' => $member->address,
                'city' => $member->city,
                'state' => $member->state,
                'occupation' => $member->occupation,
                'employer' => $member->employer,
                'job_title' => $member->job_title,
                'employment_status' => $member->employment_status,
            ],
            'document_fields' => collect(self::DOCUMENT_FIELDS)
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'required' => $key !== 'proof_of_address',
                ])
                ->values(),
            'can_submit' => ! in_array($member->kyc_status, [
                KycRecord::STATUS_SUBMITTED,
                KycRecord::STATUS_PROCESSING,
            ], true),
        ]);
    }

    /** Submit (or resubmit) a KYC application. */
    public function store(Request $request): JsonResponse
    {
        $member = $request->user()->member()->firstOrFail();

        if (in_array($member->kyc_status, [KycRecord::STATUS_SUBMITTED, KycRecord::STATUS_PROCESSING], true)) {
            throw ValidationException::withMessages([
                'status' => ['A KYC application is already awaiting review.'],
            ]);
        }

        $data = $request->validate([
            'bvn' => ['required', 'string', 'digits:11'],
            'nin' => ['required', 'string', 'digits:11'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['required', 'string', 'max:80'],
            'occupation' => ['required', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'employment_status' => ['nullable', 'string', 'max:40'],
            'bank_account_number' => ['required', 'string', 'digits:10'],
            'bank_account_name' => ['required', 'string', 'max:150'],
            'bank_name' => ['required', 'string', 'max:100'],
            'government_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'photograph' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'proof_of_address' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $profile = collect($data)->only([
            'bvn', 'nin', 'date_of_birth', 'address', 'city', 'state',
            'occupation', 'employer', 'job_title', 'employment_status',
        ])->all();

        $kycData = collect($data)->only([
            'bank_account_number', 'bank_account_name', 'bank_name',
        ])->all();

        DB::transaction(function () use ($request, $member, $profile, $kycData, $data) {
            $member->fill($profile);
            $member->kyc_status = KycRecord::STATUS_SUBMITTED;
            $member->save();

            // Supersede any earlier attempt so the queue only ever shows the
            // member's current application.
            $member->kycRecords()->whereIn('status', KycRecord::OPEN_STATUSES)->delete();

            KycRecord::create([
                'member_id' => $member->id,
                'level' => max(1, (int) $member->kyc_level),
                'status' => KycRecord::STATUS_SUBMITTED,
                'data' => $kycData,
                'documents' => $this->storeDocuments($request, $member),
                'provider' => (string) \App\Models\Setting::getValue('kyc_provider', 'manual'),
                'submitted_at' => now(),
            ]);
        });

        Auditor::record(
            'kyc.submitted',
            'kyc',
            'KYC application submitted for '.$member->member_number,
            ['kyc_status' => 'previous'],
            ['kyc_status' => KycRecord::STATUS_SUBMITTED],
            $member,
        );

        return response()->json([
            'message' => 'KYC submitted. An administrator will review your application.',
            'status' => KycRecord::STATUS_SUBMITTED,
        ], 201);
    }

    /** @return array<int, array<string, mixed>> */
    private function storeDocuments(Request $request, $member): array
    {
        $stored = [];

        foreach (array_keys(self::DOCUMENT_FIELDS) as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $path = sprintf('kyc/%d/%s-%s.%s', $member->id, $field, now()->format('YmdHis'), $extension);

            Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

            $stored[] = [
                'field' => $field,
                'label' => self::DOCUMENT_FIELDS[$field],
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        return $stored;
    }

    private function recordPayload(KycRecord $record): array
    {
        return [
            'id' => $record->id,
            'status' => $record->status,
            'level' => (int) $record->level,
            'provider' => $record->provider,
            'submitted_at' => optional($record->submitted_at)->toDateTimeString(),
            'reviewed_at' => optional($record->reviewed_at)->toDateTimeString(),
            'rejection_reason' => $record->rejection_reason,
            'data' => $record->data ?? [],
            'documents' => collect($record->documents ?? [])->map(fn (array $doc) => [
                'field' => $doc['field'],
                'label' => $doc['label'],
                'original_name' => $doc['original_name'],
                'mime' => $doc['mime'],
                'size' => $doc['size'],
            ])->values()->all(),
        ];
    }
}
