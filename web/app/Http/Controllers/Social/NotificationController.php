<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Presenters\SocialCounts;
use Illuminate\Http\Request;

/** The notification bell: the last 30 notices (texts in the user's language) and marking them read. */
class NotificationController extends Controller
{
    public function index(Request $request, SocialCounts $counts)
    {
        $u = $request->user();
        $items = $u->notifications()->latest()->limit(30)->get()->map(function ($n) {
            $type = (string) ($n->data['type'] ?? '');
            $params = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) ($n->data['params'] ?? []));

            return [
                'id' => $n->id,
                'type' => $type,
                'text' => $type !== '' ? __('notice.'.$type, $params) : '',
                'link' => $n->data['link'] ?? null,
                'name' => ($params['name'] ?? '') !== '' ? $params['name'] : null,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ];
        });

        return response()->json(['items' => $items, 'counts' => $counts->for($u)]);
    }

    /** POST {id?}: one notice, or all of them when no id is given. */
    public function read(Request $request, SocialCounts $counts)
    {
        $request->validate(['id' => 'nullable|string|max:64']);
        $u = $request->user();
        $q = $u->unreadNotifications();
        if ($request->filled('id')) {
            $q->where('id', $request->input('id'));
        }
        $q->update(['read_at' => now()]);

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['counts' => $counts->for($u)]) : back();
    }
}
