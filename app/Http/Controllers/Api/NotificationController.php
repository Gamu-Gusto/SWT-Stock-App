<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = AppNotification::query()->visibleTo($user)
            ->when($request->input('state', 'open') === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->withExists(['readers as is_read' => fn ($q) => $q->where('users.id', $user->id)])
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('updated_at');

        return response()->json($q->paginate(30));
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $open = AppNotification::query()->visibleTo($user)->whereNull('resolved_at');
        $unread = (clone $open)->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $user->id))->count();
        $byType = (clone $open)->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');

        return response()->json(['unread' => $unread, 'by_type' => $byType]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless(AppNotification::query()->visibleTo($request->user())->whereKey($notification->id)->exists(), 404);
        DB::table('notification_reads')->insertOrIgnore(['notification_id' => $notification->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = AppNotification::query()->visibleTo($user)->whereNull('resolved_at')->pluck('id');
        DB::table('notification_reads')->insertOrIgnore($ids->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
