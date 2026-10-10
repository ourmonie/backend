<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cooperative's own directory.
 *
 * A member should be able to see who else they are sharing the cooperative
 * with - a name, a number and a face, which is the digital equivalent of
 * knowing the people in the room with you.
 *
 * Deliberately thin: no balances, no contact details, no identity numbers and
 * no KYC state. Only members whose membership is active appear, and the only
 * photograph exposed is the one they were already required to upload.
 */
class MemberDirectoryController extends Controller
{
    /** Paginated directory, searchable by name or member number. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:50'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        $members = Member::query()
            ->where('membership_status', 'active')
            ->with(['user:id,name,passport_photo', 'tier:id,name,code'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($q) use ($like) {
                    $q->where('member_number', 'like', $like)
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like));
                });
            })
            ->orderBy('member_number')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->through(fn (Member $member) => [
                'id' => $member->user_id,
                'name' => $member->user?->name,
                'member_number' => $member->member_number,
                'tier' => $member->tier?->name,
                'since' => optional($member->member_since)->format('M Y'),
                'has_photo' => ! empty($member->user?->passport_photo),
            ]);

        return response()->json($members);
    }

    /** Another member's face, streamed from the private disk - never a /storage URL. */
    public function photo(int $userId): JsonResponse|StreamedResponse
    {
        $path = Member::query()
            ->where('membership_status', 'active')
            ->where('user_id', $userId)
            ->with('user:id,passport_photo')
            ->first()
            ?->user?->passport_photo;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'No photograph on file.'], 404);
        }

        return Storage::disk('local')->response($path, 'member-'.basename($path));
    }
}
