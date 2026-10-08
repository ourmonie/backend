<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::visible()
            ->limit(50)
            ->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'category' => $a->category,
                'severity' => $a->severity,
                'pinned' => (bool) $a->pinned,
                'published_at' => optional($a->published_at)->toDateTimeString(),
                'published_label' => optional($a->published_at)->diffForHumans(),
            ]);

        return response()->json(['data' => $announcements]);
    }
}
