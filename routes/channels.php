<?php

use App\Models\Conversation;
use App\Models\SocietyGroup;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Private/presence channel authorization. The auth endpoint (POST /api/v1/broadcasting/auth) runs behind
| `auth:api`, so `$user` is whoever owns the JWT. Returning false (or a missing conversation) gives a 403.
*/

$jwt = ['guards' => ['api']];

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
}, $jwt);

// Your own feed of new messages and read receipts across all conversations.
Broadcast::channel('user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
}, $jwt);

// Messages, read receipts and typing (client events) for one conversation; participants only.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    return $conversation->hasParticipant($user->id);
}, $jwt);

// Presence for one conversation: whether the other person is online, plus typing (client events). Nothing is stored.
Broadcast::channel('online.{conversation}', function (User $user, Conversation $conversation) {
    return $conversation->hasParticipant($user->id) ? ['id' => $user->id, 'name' => $user->name] : false;
}, $jwt);

// Typing (client events) in a Group Society chat; members only.
Broadcast::channel('group.{group}', function (User $user, SocietyGroup $group) {
    return $group->membership($user->id) !== null;
}, $jwt);
