<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Player\ChatController;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Response;

/** Chats inside the owner panel (its menu stays): the same pages as the player's Messages, shown in the panel. */
class OwnerChatController extends ChatController
{
    /** The place panel lists only the chats of the place picked (its players' messages, its tournaments). */
    protected function placeOf(Request $request): ?int
    {
        return $request->attributes->get('venue')?->id;
    }

    public function index(Request $request): Response
    {
        return parent::index($request)->with('panel', true);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        return parent::show($request, $conversation)->with('panel', true);
    }
}
