<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        $query = $member->notifications()->orderByDesc('created_at');

        if ($request->boolean('unread')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate(min(50, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($notifications->items())->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'severity' => $n->severity,
                'channel' => $n->channel,
                'url' => $n->url,
                'is_read' => (bool) $n->is_read,
                'created_label' => $n->created_at->diffForHumans(),
                'created_at' => $n->created_at->toDateTimeString(),
            ]),
            'unread' => $member->notifications()->where('is_read', false)->count(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        if ($request->filled('ids')) {
            $member->notifications()
                ->whereIn('id', (array) $request->input('ids'))
                ->update(['is_read' => true, 'read_at' => now()]);
        } else {
            $member->notifications()->where('is_read', false)
                ->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json([
            'unread' => $member->notifications()->where('is_read', false)->count(),
        ]);
    }
}
