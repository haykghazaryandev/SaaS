<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Player\ChatController;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Response;

/** Chats inside the owner panel (its menu stays): the same pages as the player's Messages, shown in the panel. */
class OwnerChatController extends ChatController
{
    public function index(Request $request): Response
    {
        return parent::index($request)->with('panel', true);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        return parent::show($request, $conversation)->with('panel', true);
    }
}
