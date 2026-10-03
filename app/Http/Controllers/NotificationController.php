<?php

namespace App\Http\Controllers;

use App\Models\ClubActivity;
use App\Models\Friendship;
use App\Models\TournamentInvite;
use App\Models\User;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bell: things other people did that involve you, built from existing activity (friend requests, likes,
 * comments, reposts, join requests, new club activities, tournament invites) from the last 30 days. Anything newer than
 * `notifications_seen_at` counts as unread.
 */
class NotificationController extends Controller
{
    private const DAYS = 30;

    private const LIMIT = 40;

    public function index(): JsonResponse
    {
        /** @var User $me */
        $me = auth('api')->user();
        $since = now()->subDays(self::DAYS);

        $items = collect()
            ->concat($this->friendRequests($me->id, $since))
            ->concat($this->acceptedRequests($me->id, $since))
            ->concat($this->likes($me->id, $since))
            ->concat($this->comments($me->id, $since))
            ->concat($this->reposts($me->id, $since))
            ->concat($this->joinRequests($me->id, $since))
            ->concat($this->clubActivities($me->id, $since))
            ->concat($this->tournamentInvites($me->id, $since))
            ->sortByDesc(fn (array $item) => $item['created_at']->getTimestamp())
            ->take(self::LIMIT)
            ->values();

        $seenAt = $me->notifications_seen_at;
        $data = $items->map(fn (array $item) => [
            ...$item,
            'unread' => $seenAt === null || $item['created_at']->greaterThan($seenAt),
            'created_at' => $item['created_at']->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'unread_count' => $data->where('unread', true)->count(),
        ]);
    }

    /** Opening the bell marks everything up to now as seen. */
    public function markRead(): JsonResponse
    {
        /** @var User $me */
        $me = auth('api')->user();
        $me->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['message' => 'Notifications marked as read.', 'unread_count' => 0]);
    }

    private function item(string $id, string $type, ?User $actor, string $url, Carbon $at, array $extra = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'actor' => $actor ? UserPresenter::author($actor) : null,
            'others_count' => 0,
            'subject' => null,
            'context' => null,
            'url' => $url,
            'created_at' => $at,
            ...$extra,
        ];
    }

    private function snippet(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        return $text === '' ? null : Str::limit($text, 80);
    }

    private function friendRequests(int $me, Carbon $since): Collection
    {
        return Friendship::query()
            ->with('requester')
            ->where('addressee_id', $me)
            ->where('status', Friendship::PENDING)
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Friendship $f) => $this->item(
                'friend-request-'.$f->id, 'friend_request', $f->requester, '/society', $f->created_at,
            ));
    }

    private function acceptedRequests(int $me, Carbon $since): Collection
    {
        return Friendship::query()
            ->with('addressee')
            ->where('requester_id', $me)
            ->where('status', Friendship::ACCEPTED)
            ->where('accepted_at', '>=', $since)
            ->latest('accepted_at')
            ->limit(20)
            ->get()
            ->map(fn (Friendship $f) => $this->item(
                'friend-accepted-'.$f->id, 'friend_accepted', $f->addressee, '/people/'.$f->addressee_id, $f->accepted_at,
            ));
    }

    /** One entry per post: the latest person to react, how many others, and which reactions were used. */
    private function likes(int $me, Carbon $since): Collection
    {
        $rows = DB::table('post_likes')
            ->join('posts', 'posts.id', '=', 'post_likes.post_id')
            ->where('posts.user_id', $me)
            ->where('post_likes.user_id', '!=', $me)
            ->where('post_likes.created_at', '>=', $since)
            ->orderByDesc('post_likes.created_at')
            ->orderByDesc('post_likes.id')
            ->limit(300)
            ->get(['post_likes.post_id', 'post_likes.user_id', 'post_likes.reaction', 'post_likes.created_at', 'posts.body']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return $rows->groupBy('post_id')->map(function (Collection $likes, $postId) use ($users) {
            $latest = $likes->first();

            return $this->item(
                'like-'.$postId.'-'.$likes->count(),
                'post_like',
                $users->get($latest->user_id),
                '/feed?post='.$postId,
                Carbon::parse($latest->created_at),
                [
                    'others_count' => $likes->pluck('user_id')->unique()->count() - 1,
                    'subject' => $this->snippet($latest->body),
                    'reaction' => $latest->reaction,
                    'reactions' => $likes->countBy('reaction')->sortDesc()->keys()->values()->all(),
                ],
            );
        })->values();
    }

    private function comments(int $me, Carbon $since): Collection
    {
        $rows = DB::table('post_comments')
            ->join('posts', 'posts.id', '=', 'post_comments.post_id')
            ->where('posts.user_id', $me)
            ->where('post_comments.user_id', '!=', $me)
            ->where('post_comments.created_at', '>=', $since)
            ->orderByDesc('post_comments.created_at')
            ->limit(30)
            ->get(['post_comments.id', 'post_comments.post_id', 'post_comments.user_id', 'post_comments.body', 'post_comments.created_at']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return $rows->map(fn ($row) => $this->item(
            'comment-'.$row->id, 'post_comment', $users->get($row->user_id), '/feed?post='.$row->post_id,
            Carbon::parse($row->created_at), ['subject' => $this->snippet($row->body)],
        ));
    }

    private function reposts(int $me, Carbon $since): Collection
    {
        $rows = DB::table('posts as repost')
            ->join('posts as original', 'original.id', '=', 'repost.repost_of_id')
            ->where('original.user_id', $me)
            ->where('repost.user_id', '!=', $me)
            ->where('repost.created_at', '>=', $since)
            ->orderByDesc('repost.created_at')
            ->limit(30)
            ->get(['repost.id', 'repost.user_id', 'repost.created_at', 'original.id as original_id', 'original.body']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return $rows->map(fn ($row) => $this->item(
            'repost-'.$row->id, 'repost', $users->get($row->user_id), '/feed?post='.$row->original_id,
            Carbon::parse($row->created_at), ['subject' => $this->snippet($row->body)],
        ));
    }

    /** People asking to join clubs you own. */
    private function joinRequests(int $me, Carbon $since): Collection
    {
        $rows = DB::table('club_join_requests')
            ->join('clubs', 'clubs.id', '=', 'club_join_requests.club_id')
            ->where('clubs.owner_id', $me)
            ->where('club_join_requests.created_at', '>=', $since)
            ->orderByDesc('club_join_requests.created_at')
            ->limit(30)
            ->get(['club_join_requests.id', 'club_join_requests.user_id', 'club_join_requests.created_at', 'clubs.name', 'clubs.slug', 'clubs.type']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return $rows->map(fn ($row) => $this->item(
            'join-request-'.$row->id, 'join_request', $users->get($row->user_id),
            ($row->type === 'community' ? '/communities/' : '/clubs/').$row->slug,
            Carbon::parse($row->created_at), ['subject' => $row->name],
        ));
    }

    /** Activities someone else planned in a club you're in. */
    private function clubActivities(int $me, Carbon $since): Collection
    {
        return ClubActivity::query()
            ->with(['club', 'user'])
            ->whereHas('club.members', fn (Builder $q) => $q->where('users.id', $me))
            ->where('user_id', '!=', $me)
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ClubActivity $a) => $this->item(
                'activity-'.$a->id, 'club_activity', $a->user, '/activities', $a->created_at,
                ['subject' => $a->title, 'context' => $a->club->name],
            ));
    }

    /** Invites to tournaments you haven't entered or declined yet. */
    private function tournamentInvites(int $me, Carbon $since): Collection
    {
        return TournamentInvite::query()
            ->with(['tournament', 'inviter'])
            ->where('user_id', $me)
            ->where('status', TournamentInvite::PENDING)
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (TournamentInvite $i) => $this->item(
                'tournament-invite-'.$i->id, 'tournament_invite', $i->inviter, '/tournaments/'.$i->tournament->slug,
                $i->created_at, ['subject' => $i->tournament->name],
            ));
    }
}
