<?php

namespace App\Http\Controllers;

use App\Http\Requests\Feed\StorePostRequest;
use App\Models\Club;
use App\Models\CommentLike;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\Tournament;
use App\Models\User;
use App\Support\ClubPresenter;
use App\Support\Reactions;
use App\Support\TournamentPresenter;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    /** @var array<int, array<int, array{type: string, count: int}>> */
    private array $reactionSummary = [];

    /** @var array<int, array{reactions: array<int, array{type: string, count: int}>, count: int, mine: ?string}> */
    private array $commentReactions = [];

    /** @var array<int, array> What the viewer has to do with each shared tournament, loaded once per request. */
    private array $tournamentContexts = [];

    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();

        $page = $this->feedQuery($viewerId)
            ->orderByDesc('id')
            ->cursorPaginate(10);

        return response()->json([
            'data' => collect($this->withReactions($page->items()))
                ->map(fn (Post $post) => $this->transformPost($post, $viewerId))
                ->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** A club's wall: posts shared in that club, newest first. Members only. */
    public function wall(Club $club): JsonResponse
    {
        $viewerId = $this->viewerId();

        if (! $club->hasMember($viewerId)) {
            return response()->json(['message' => 'Join '.$club->name.' to see its wall.'], 403);
        }

        $page = $this->feedQuery($viewerId)
            ->where('posts.club_id', $club->id)
            ->orderByDesc('id')
            ->cursorPaginate(10);

        return response()->json([
            'data' => collect($this->withReactions($page->items()))
                ->map(fn (Post $post) => $this->transformPost($post, $viewerId))
                ->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** Posts someone wrote (and reposted), newest first, filtered by what the viewer may see. */
    public function byUser(User $user): JsonResponse
    {
        $viewerId = $this->viewerId();

        $page = $this->feedQuery($viewerId)
            ->where('posts.user_id', $user->id)
            ->orderByDesc('id')
            ->cursorPaginate(10);

        return response()->json([
            'data' => collect($this->withReactions($page->items()))
                ->map(fn (Post $post) => $this->transformPost($post, $viewerId))
                ->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(int $post): JsonResponse
    {
        $viewerId = $this->viewerId();

        return response()->json([
            'post' => $this->transformPost($this->feedQuery($viewerId)->findOrFail($post), $viewerId),
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $data = $request->validated();

        $club = empty($data['club_id']) ? null : Club::query()->findOrFail($data['club_id']);

        // Public tournaments go to the feed (or a club you pick); private club tournaments stay on their club's wall.
        $tournament = empty($data['tournament_id']) ? null : Tournament::query()->with('club')->findOrFail($data['tournament_id']);
        if ($tournament && ! $tournament->canView($viewerId)) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }
        if ($tournament && ! $tournament->isPublic()) {
            if (! $tournament->club) {
                return response()->json(['message' => 'Invite-only tournaments can’t be shared. Invite people from the tournament instead.'], 422);
            }
            $club = $tournament->club;
        }

        if ($club && ! $club->hasMember($viewerId)) {
            return response()->json(['message' => 'Join '.$club->name.' to post there.'], 403);
        }

        $repostOfId = null;
        if (! empty($data['repost_of_id'])) {
            $target = Post::query()->findOrFail($data['repost_of_id']);
            if ($denied = $this->denyUnlessRepostable($target, $viewerId)) {
                return $denied;
            }
            $repostOfId = $this->repostTarget($target)->id;
        }

        $post = Post::query()->create([
            'user_id' => $viewerId,
            'club_id' => $club?->id,
            'tournament_id' => $tournament?->id,
            'body' => $data['body'] ?? null,
            'image_path' => $request->file('image')?->store('posts', 'media'),
            'repost_of_id' => $repostOfId,
        ]);

        return response()->json([
            'message' => $tournament ? ($club ? 'Shared to '.$club->name.'.' : 'Shared to your feed.') : 'Posted.',
            'post' => $this->transformPost($this->feedQuery($viewerId)->findOrFail($post->id), $viewerId),
        ], 201);
    }

    public function destroy(Post $post): JsonResponse
    {
        if ((int) $post->user_id !== $this->viewerId()) {
            return response()->json(['message' => 'You can only delete your own posts.'], 403);
        }

        $post->reposts()->whereNull('body')->whereNull('image_path')->delete();

        if ($post->image_path) {
            Storage::disk('media')->delete($post->image_path);
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    /** React to a post (a plain like by default); reacting again with another reaction switches it. */
    public function like(Request $request, Post $post): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($post)) {
            return $denied;
        }

        $data = $request->validate([
            'reaction' => ['sometimes', Rule::in(PostLike::REACTIONS)],
        ], [
            'reaction.in' => 'Pick one of the reactions.',
        ]);
        $reaction = $data['reaction'] ?? 'like';

        PostLike::query()->updateOrCreate(
            ['post_id' => $post->id, 'user_id' => $this->viewerId()],
            ['reaction' => $reaction],
        );

        return $this->likeResponse($post, $reaction);
    }

    public function unlike(Post $post): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($post)) {
            return $denied;
        }

        $post->likes()->where('user_id', $this->viewerId())->delete();

        return $this->likeResponse($post, null);
    }

    /** Who reacted, newest first (200 max), with a count per reaction. */
    public function reactions(Post $post): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($post)) {
            return $denied;
        }

        $likes = $post->likes()->with('user')->latest('updated_at')->limit(200)->get();

        return response()->json([
            'data' => $likes->map(fn (PostLike $like) => [
                'user' => UserPresenter::author($like->user),
                'reaction' => $like->reaction,
                'is_me' => (int) $like->user_id === $viewerId,
            ]),
            'reactions' => $this->reactionSummaryFor($post->id, fresh: true),
            'total' => $post->likes()->count(),
        ]);
    }

    public function repost(Post $post): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessRepostable($post, $viewerId)) {
            return $denied;
        }
        $target = $this->repostTarget($post);

        $repost = Post::query()->firstOrCreate([
            'user_id' => $viewerId,
            'repost_of_id' => $target->id,
            'body' => null,
            'image_path' => null,
        ]);

        return response()->json([
            'message' => 'Reposted.',
            'post_id' => $target->id,
            'reposted' => true,
            'reposts_count' => $target->reposts()->count(),
            'post' => $this->transformPost($this->feedQuery($viewerId)->findOrFail($repost->id), $viewerId),
        ], $repost->wasRecentlyCreated ? 201 : 200);
    }

    public function undoRepost(Post $post): JsonResponse
    {
        $target = $this->repostTarget($post);

        $target->reposts()
            ->where('user_id', $this->viewerId())
            ->whereNull('body')
            ->whereNull('image_path')
            ->delete();

        return response()->json([
            'message' => 'Repost removed.',
            'post_id' => $target->id,
            'reposted' => false,
            'reposts_count' => $target->reposts()->count(),
        ]);
    }

    public function comments(Post $post): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($post)) {
            return $denied;
        }

        $comments = $post->comments()
            ->with('user')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $this->loadCommentReactions($comments->pluck('id')->all(), $viewerId);

        return response()->json([
            'data' => $comments->map(fn (PostComment $comment) => $this->transformComment($comment, $viewerId, $post)),
        ]);
    }

    public function storeComment(Request $request, Post $post): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($post)) {
            return $denied;
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ], [
            'body.required' => 'Write a comment first.',
        ]);

        $comment = $post->comments()->create([
            'user_id' => $viewerId,
            'body' => trim($data['body']),
        ]);
        $comment->load('user');

        return response()->json([
            'message' => 'Comment added.',
            'comment' => $this->transformComment($comment, $viewerId, $post),
            'comments_count' => $post->comments()->count(),
        ], 201);
    }

    public function destroyComment(PostComment $comment): JsonResponse
    {
        $viewerId = $this->viewerId();
        $post = $comment->post;

        if ((int) $comment->user_id !== $viewerId && (int) $post->user_id !== $viewerId) {
            return response()->json(['message' => 'You can only delete your own comments.'], 403);
        }

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted.',
            'post_id' => $post->id,
            'comments_count' => $post->comments()->count(),
        ]);
    }

    /** React to a comment (a plain like by default); reacting again with another reaction switches it. */
    public function likeComment(Request $request, PostComment $comment): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($comment->post)) {
            return $denied;
        }

        $data = $request->validate([
            'reaction' => ['sometimes', Rule::in(PostLike::REACTIONS)],
        ], [
            'reaction.in' => 'Pick one of the reactions.',
        ]);

        CommentLike::query()->updateOrCreate(
            ['post_comment_id' => $comment->id, 'user_id' => $this->viewerId()],
            ['reaction' => $data['reaction'] ?? 'like'],
        );

        return $this->commentLikeResponse($comment);
    }

    public function unlikeComment(PostComment $comment): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($comment->post)) {
            return $denied;
        }

        $comment->likes()->where('user_id', $this->viewerId())->delete();

        return $this->commentLikeResponse($comment);
    }

    /** Who reacted to a comment, newest first (200 max), with a count per reaction. */
    public function commentReactions(PostComment $comment): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($comment->post)) {
            return $denied;
        }

        $likes = $comment->likes()->with('user')->latest('updated_at')->limit(200)->get();
        $this->loadCommentReactions([$comment->id], $viewerId);

        return response()->json([
            'data' => $likes->map(fn (CommentLike $like) => [
                'user' => UserPresenter::author($like->user),
                'reaction' => $like->reaction,
                'is_me' => (int) $like->user_id === $viewerId,
            ]),
            'reactions' => $this->commentReactions[$comment->id]['reactions'],
            'total' => $this->commentReactions[$comment->id]['count'],
        ]);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }

    private function feedQuery(int $viewerId)
    {
        return Post::query()
            ->forViewer($viewerId)
            ->with(['repostOf' => fn ($query) => $query->forViewer($viewerId)]);
    }

    private function denyUnlessVisible(Post $post): ?JsonResponse
    {
        return $post->isVisibleTo($this->viewerId()) ? null : response()->json(['message' => 'Post not found.'], 404);
    }

    /** Club posts stay inside their club, so they can't be shared to the main feed. */
    private function denyUnlessRepostable(Post $post, int $viewerId): ?JsonResponse
    {
        if (! $post->isVisibleTo($viewerId)) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $target = $this->repostTarget($post);
        if ($target->club_id !== null) {
            return response()->json(['message' => 'Posts in a club or community can’t be reposted.'], 422);
        }

        return null;
    }

    /** Reposting a plain repost reposts the original post instead. */
    private function repostTarget(Post $post): Post
    {
        if ($post->isPlainRepost() && $post->repostOf) {
            return $post->repostOf;
        }

        return $post;
    }

    private function likeResponse(Post $post, ?string $reaction): JsonResponse
    {
        return response()->json([
            'post_id' => $post->id,
            'liked' => $reaction !== null,
            'my_reaction' => $reaction,
            'likes_count' => $post->likes()->count(),
            'reactions' => $this->reactionSummaryFor($post->id, fresh: true),
        ]);
    }

    /**
     * A page of posts plus the originals they repost, with reaction counts fetched in one query.
     *
     * @param  iterable<Post>  $posts
     * @return iterable<Post>
     */
    private function withReactions(iterable $posts): iterable
    {
        $ids = [];
        foreach ($posts as $post) {
            $ids[] = $post->id;
            if ($post->relationLoaded('repostOf') && $post->repostOf) {
                $ids[] = $post->repostOf->id;
            }
        }
        $this->loadReactions($ids);

        return $posts;
    }

    /**
     * Reaction counts per post, most used first.
     *
     * @param  array<int, int>  $ids
     */
    private function loadReactions(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->reactionSummary)));
        if ($ids === []) {
            return;
        }

        $rows = PostLike::query()
            ->whereIn('post_id', $ids)
            ->selectRaw('post_id, reaction, COUNT(*) as total')
            ->groupBy('post_id', 'reaction')
            ->get()
            ->groupBy('post_id');

        foreach ($ids as $id) {
            $this->reactionSummary[$id] = Reactions::sorted($rows->get($id) ?? collect());
        }
    }

    /**
     * Each comment's reaction counts and the viewer's own reaction, in two queries.
     *
     * @param  array<int, int>  $ids
     */
    private function loadCommentReactions(array $ids, int $viewerId): void
    {
        if ($ids === []) {
            return;
        }

        $rows = CommentLike::query()
            ->whereIn('post_comment_id', $ids)
            ->selectRaw('post_comment_id, reaction, COUNT(*) as total')
            ->groupBy('post_comment_id', 'reaction')
            ->get()
            ->groupBy('post_comment_id');
        $mine = CommentLike::query()
            ->whereIn('post_comment_id', $ids)
            ->where('user_id', $viewerId)
            ->pluck('reaction', 'post_comment_id');

        foreach ($ids as $id) {
            $reactions = Reactions::sorted($rows->get($id) ?? collect());
            $this->commentReactions[$id] = [
                'reactions' => $reactions,
                'count' => array_sum(array_column($reactions, 'count')),
                'mine' => $mine->get($id),
            ];
        }
    }

    private function commentLikeResponse(PostComment $comment): JsonResponse
    {
        $this->loadCommentReactions([$comment->id], $this->viewerId());
        $summary = $this->commentReactions[$comment->id];

        return response()->json([
            'comment_id' => $comment->id,
            'my_reaction' => $summary['mine'],
            'likes_count' => $summary['count'],
            'reactions' => $summary['reactions'],
        ]);
    }

    /** @return array<int, array{type: string, count: int}> */
    private function reactionSummaryFor(int $postId, bool $fresh = false): array
    {
        if ($fresh) {
            unset($this->reactionSummary[$postId]);
        }
        if (! array_key_exists($postId, $this->reactionSummary)) {
            $this->loadReactions([$postId]);
        }

        return $this->reactionSummary[$postId];
    }

    /** The shared tournament's card, or null when there's none or the viewer can't see it (anymore). */
    private function sharedTournament(Post $post, int $viewerId): ?array
    {
        $tournament = $post->relationLoaded('tournament') ? $post->tournament : null;
        if (! $tournament || ! $tournament->canView($viewerId)) {
            return null;
        }
        $this->tournamentContexts[$tournament->id] ??= TournamentPresenter::context($viewerId, [$tournament->id]);

        return TournamentPresenter::summary($tournament, $this->tournamentContexts[$tournament->id]);
    }

    private function transformPost(Post $post, int $viewerId, bool $withOriginal = true): array
    {
        $original = $withOriginal && $post->relationLoaded('repostOf') ? $post->repostOf : null;
        $tournament = $this->sharedTournament($post, $viewerId);

        return [
            'id' => $post->id,
            'body' => $post->body,
            'image_url' => $post->image_path ? Storage::disk('media')->url($post->image_path) : null,
            'created_at' => $post->created_at?->toIso8601String(),
            'author' => UserPresenter::author($post->user),
            'club' => $post->club ? ClubPresenter::summary($post->club) : null,
            'tournament' => $tournament,
            // A tournament was shared but it's private to the viewer now.
            'tournament_hidden' => $post->tournament_id !== null && $tournament === null,
            'is_mine' => (int) $post->user_id === $viewerId,
            'is_plain_repost' => $post->isPlainRepost(),
            'likes_count' => (int) ($post->likes_count ?? 0),
            'comments_count' => (int) ($post->comments_count ?? 0),
            'reposts_count' => (int) ($post->reposts_count ?? 0),
            'liked' => (bool) ($post->liked ?? false),
            'my_reaction' => $post->my_reaction,
            'reactions' => $this->reactionSummaryFor($post->id),
            'reposted' => (bool) ($post->reposted ?? false),
            'repost_of' => $original ? $this->transformPost($original, $viewerId, false) : null,
        ];
    }

    private function transformComment(PostComment $comment, int $viewerId, Post $post): array
    {
        if (! array_key_exists($comment->id, $this->commentReactions)) {
            $this->loadCommentReactions([$comment->id], $viewerId);
        }
        $summary = $this->commentReactions[$comment->id];

        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
            'author' => UserPresenter::author($comment->user),
            'can_delete' => (int) $comment->user_id === $viewerId || (int) $post->user_id === $viewerId,
            'likes_count' => $summary['count'],
            'my_reaction' => $summary['mine'],
            'reactions' => $summary['reactions'],
        ];
    }
}
