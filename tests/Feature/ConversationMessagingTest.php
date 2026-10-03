<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\SocietyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ConversationMessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $a;

    private User $b;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::factory()->create(['name' => 'Ana A']);
        $this->b = User::factory()->create(['name' => 'Ben B']);
        $this->stranger = User::factory()->create(['name' => 'Cy C']);
        Friendship::query()->create([
            'requester_id' => $this->a->id,
            'addressee_id' => $this->b->id,
            'status' => Friendship::ACCEPTED,
            'accepted_at' => now(),
        ]);
    }

    /** Each request authenticates from scratch with that user's JWT, like separate browsers. */
    private function as(?User $user, string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        // jwt-auth keeps the parsed token on singletons; every real request is a fresh process, tests aren't.
        $this->app['tymon.jwt']->unsetToken();
        JWTAuth::unsetToken();
        $this->app['auth']->forgetGuards();
        $headers = ['Accept' => 'application/json'];
        $token ??= $user ? JWTAuth::fromUser($user) : null;
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->json($method, $uri, $data, $headers);
    }

    private function conversation(): Conversation
    {
        return Conversation::between($this->a, $this->b);
    }

    public function test_starting_a_conversation_is_idempotent_and_friends_only(): void
    {
        $first = $this->as($this->a, 'POST', '/api/v1/conversations', ['user_id' => $this->b->id])->assertCreated();
        $again = $this->as($this->b, 'POST', '/api/v1/conversations', ['user_id' => $this->a->id])->assertOk();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame($this->b->id, $first->json('data.other_participant.id'));
        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(2, Conversation::query()->first()->participantRecords()->count());

        $this->as($this->a, 'POST', '/api/v1/conversations', ['user_id' => $this->stranger->id])->assertForbidden();
        $this->as($this->a, 'POST', '/api/v1/conversations', ['user_id' => $this->a->id])->assertUnprocessable();
        $this->as($this->a, 'POST', '/api/v1/conversations', ['user_id' => 999999])->assertNotFound();
    }

    public function test_sending_saves_as_the_jwt_user_and_broadcasts_after_saving(): void
    {
        Event::fake([MessageSent::class]);
        $conversation = $this->conversation();

        $response = $this->as($this->a, 'POST', "/api/v1/conversations/{$conversation->id}/messages", [
            'message' => '  Hello User B  ',
            'sender_id' => $this->b->id,
            'client_id' => 'tmp-123',
        ])->assertCreated();

        $response->assertJsonPath('data.message', 'Hello User B')
            ->assertJsonPath('data.sender_id', $this->a->id)
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.is_read', false)
            ->assertJsonPath('data.read_at', null)
            ->assertJsonPath('data.client_id', 'tmp-123');

        $message = Message::query()->sole();
        $this->assertSame($this->a->id, (int) $message->sender_id);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversation, $message) {
            $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->message->is($message)
                && $channels === [
                    'private-conversation.'.$conversation->id,
                    'private-user.'.$this->a->id,
                    'private-user.'.$this->b->id,
                ]
                && $payload['id'] === $message->id
                && $payload['conversation_id'] === $conversation->id
                && $payload['sender_id'] === $this->a->id
                && $payload['message'] === 'Hello User B'
                && $payload['client_id'] === 'tmp-123'
                && $event->broadcastAs() === 'MessageSent';
        });
    }

    public function test_message_validation(): void
    {
        Event::fake([MessageSent::class]);
        $uri = "/api/v1/conversations/{$this->conversation()->id}/messages";

        $this->as($this->a, 'POST', $uri, [])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->as($this->a, 'POST', $uri, ['message' => '    '])->assertUnprocessable();
        $this->as($this->a, 'POST', $uri, ['message' => str_repeat('x', Message::MAX_LENGTH + 1)])->assertUnprocessable();
        $this->assertSame(0, Message::query()->count());
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_outsiders_cannot_read_send_or_mark_read(): void
    {
        Event::fake([MessageSent::class, MessagesRead::class]);
        $conversation = $this->conversation();
        $conversation->messages()->create(['sender_id' => $this->a->id, 'message' => 'private']);
        $id = $conversation->id;

        $this->as($this->stranger, 'GET', "/api/v1/conversations/{$id}")->assertNotFound();
        $this->as($this->stranger, 'GET', "/api/v1/conversations/{$id}/messages")->assertNotFound();
        $this->as($this->stranger, 'POST', "/api/v1/conversations/{$id}/messages", ['message' => 'hi'])->assertNotFound();
        $this->as($this->stranger, 'POST', "/api/v1/conversations/{$id}/read")->assertNotFound();
        $this->as($this->stranger, 'GET', '/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(1, Message::query()->count());
        $this->assertFalse((bool) Message::query()->first()->is_read);
        Event::assertNotDispatched(MessageSent::class);
        Event::assertNotDispatched(MessagesRead::class);
    }

    public function test_requests_without_a_valid_jwt_are_rejected(): void
    {
        $id = $this->conversation()->id;

        $this->as(null, 'GET', '/api/v1/conversations')->assertUnauthorized();
        $this->as(null, 'POST', "/api/v1/conversations/{$id}/messages", ['message' => 'hi'])->assertUnauthorized();
        $this->as(null, 'GET', '/api/v1/conversations', [], 'not-a-jwt')->assertUnauthorized();

        $expired = JWTAuth::fromUser($this->a);
        $this->travel(config('jwt.ttl') + 5)->minutes();
        $this->as(null, 'GET', '/api/v1/conversations', [], $expired)->assertUnauthorized();
        $this->as(null, 'POST', '/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-conversation.{$id}",
        ], $expired)->assertUnauthorized();
    }

    public function test_conversation_list_has_latest_message_and_unread_counts(): void
    {
        Event::fake([MessageSent::class]);
        $conversation = $this->conversation();
        $uri = "/api/v1/conversations/{$conversation->id}/messages";
        $this->as($this->a, 'POST', $uri, ['message' => 'one'])->assertCreated();
        $this->as($this->a, 'POST', $uri, ['message' => 'two'])->assertCreated();

        $forB = $this->as($this->b, 'GET', '/api/v1/conversations')->assertOk();
        $forB->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.other_participant.id', $this->a->id)
            ->assertJsonPath('data.0.other_participant.name', 'Ana A')
            ->assertJsonPath('data.0.latest_message.message', 'two')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('unread_count', 2);
        $this->assertNotNull($forB->json('data.0.latest_message_at'));

        $this->as($this->a, 'GET', '/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);

        // Conversations without messages stay out of the list.
        Conversation::between($this->b, tap($this->stranger, fn ($s) => Friendship::query()->create([
            'requester_id' => $this->b->id, 'addressee_id' => $s->id, 'status' => Friendship::ACCEPTED,
        ])));
        $this->as($this->b, 'GET', '/api/v1/conversations')->assertJsonCount(1, 'data');
    }

    public function test_messages_are_paginated_fifty_at_a_time(): void
    {
        $conversation = $this->conversation();
        foreach (range(1, 120) as $i) {
            $conversation->messages()->create(['sender_id' => $i % 2 ? $this->a->id : $this->b->id, 'message' => "m{$i}"]);
        }
        $uri = "/api/v1/conversations/{$conversation->id}/messages";

        $first = $this->as($this->b, 'GET', $uri)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('has_more', true);
        $this->assertSame('m71', $first->json('data.0.message'));
        $this->assertSame('m120', $first->json('data.49.message'));

        $second = $this->as($this->b, 'GET', $uri.'?before='.$first->json('next_before'))->assertJsonCount(50, 'data');
        $this->assertSame('m21', $second->json('data.0.message'));

        $third = $this->as($this->b, 'GET', $uri.'?before='.$second->json('next_before'))
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_before', null);
        $this->assertSame('m1', $third->json('data.0.message'));
    }

    public function test_reading_marks_only_the_other_persons_messages_and_broadcasts(): void
    {
        Event::fake([MessageSent::class, MessagesRead::class]);
        $conversation = $this->conversation();
        $fromA = $conversation->messages()->create(['sender_id' => $this->a->id, 'message' => 'from A']);
        $fromB = $conversation->messages()->create(['sender_id' => $this->b->id, 'message' => 'from B']);

        $result = $this->as($this->b, 'POST', "/api/v1/conversations/{$conversation->id}/read")->assertOk();
        $result->assertJsonPath('message_ids', [$fromA->id])->assertJsonPath('unread_count', 0);

        $this->assertTrue($fromA->fresh()->is_read);
        $this->assertNotNull($fromA->fresh()->read_at);
        $this->assertFalse($fromB->fresh()->is_read);

        Event::assertDispatched(MessagesRead::class, fn (MessagesRead $e) => $e->readerId === $this->b->id
            && $e->messageIds === [$fromA->id]
            && in_array('private-user.'.$this->a->id, array_map(fn ($c) => $c->name, $e->broadcastOn()), true));

        // Nothing left to read: no second broadcast.
        $this->as($this->b, 'POST', "/api/v1/conversations/{$conversation->id}/read")->assertJsonPath('message_ids', []);
        Event::assertDispatchedTimes(MessagesRead::class, 1);
    }

    public function test_private_channels_only_authorize_participants(): void
    {
        $id = $this->conversation()->id;
        $auth = fn (?User $user, string $channel) => $this->as($user, 'POST', '/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);

        $auth($this->a, "private-conversation.{$id}")->assertOk()->assertJsonStructure(['auth']);
        $auth($this->b, "private-conversation.{$id}")->assertOk();
        $auth($this->stranger, "private-conversation.{$id}")->assertForbidden();
        $auth($this->a, 'private-conversation.999999')->assertForbidden();

        $auth($this->a, "private-user.{$this->a->id}")->assertOk();
        $auth($this->a, "private-user.{$this->b->id}")->assertForbidden();

        $presence = $auth($this->b, "presence-online.{$id}")->assertOk();
        $this->assertSame($this->b->id, (int) json_decode($presence->json('channel_data'), true)['user_id']);
        $auth($this->stranger, "presence-online.{$id}")->assertForbidden();

        $auth(null, "private-conversation.{$id}")->assertUnauthorized();
    }

    public function test_group_typing_channel_only_authorizes_members(): void
    {
        $group = SocietyGroup::query()->create(['name' => 'Crew', 'owner_id' => $this->a->id]);
        foreach ([$this->a, $this->b] as $member) {
            $group->members()->create(['user_id' => $member->id, 'added_by' => $this->a->id]);
        }
        $auth = fn (?User $user) => $this->as($user, 'POST', '/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-group.{$group->id}",
        ]);

        $auth($this->a)->assertOk()->assertJsonStructure(['auth']);
        $auth($this->b)->assertOk();
        $auth($this->stranger)->assertForbidden();
        $auth(null)->assertUnauthorized();
    }

    public function test_a_reverb_outage_does_not_lose_the_message(): void
    {
        // No Event::fake: the broadcast really goes to Reverb, which isn't listening on port 1 in tests.
        $conversation = $this->conversation();

        $this->as($this->a, 'POST', "/api/v1/conversations/{$conversation->id}/messages", ['message' => 'still saved'])
            ->assertCreated()
            ->assertJsonPath('live', false);

        $this->assertSame('still saved', Message::query()->sole()->message);
    }

    public function test_the_my_society_chat_routes_use_the_same_conversation(): void
    {
        Event::fake([MessageSent::class, MessagesRead::class]);

        $this->as($this->a, 'POST', "/api/v1/society/{$this->b->id}/messages", ['body' => 'via society'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'via society')
            ->assertJsonPath('message.mine', true);

        $conversation = Conversation::query()->sole();
        $this->assertSame('via society', $conversation->messages()->sole()->message);
        Event::assertDispatched(MessageSent::class);

        $overview = $this->as($this->b, 'GET', '/api/v1/society')->assertOk();
        $overview->assertJsonPath('friends.0.conversation_id', $conversation->id)
            ->assertJsonPath('friends.0.last_message.body', 'via society')
            ->assertJsonPath('friends.0.unread_count', 1)
            ->assertJsonPath('unread_count', 1);

        $this->as($this->b, 'GET', "/api/v1/society/{$this->a->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'via society')
            ->assertJsonPath('data.0.mine', false);
        $this->assertTrue($conversation->messages()->sole()->is_read);

        $this->as($this->stranger, 'GET', "/api/v1/society/{$this->a->id}/messages")->assertForbidden();
    }

    public function test_existing_health_and_root_endpoints_still_work(): void
    {
        $this->getJson('/api')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'healthy');
        $this->getJson('/api/v1')->assertOk()->assertJsonPath('version', 'v1');
    }
}
