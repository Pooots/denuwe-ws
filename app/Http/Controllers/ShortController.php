<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Friendship;
use App\Models\PostLike;
use App\Models\Short;
use App\Models\ShortComment;
use App\Models\ShortCommentLike;
use App\Models\ShortLike;
use App\Models\ShortView;
use App\Support\ClubPresenter;
use App\Support\Reactions;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Shorts: vertical videos up to a minute, shared with everyone, your society or one of your clubs. */
class ShortController extends Controller
{
    /** @var array<int, array<int, array{type: string, count: int}>> */
    private array $reactionSummary = [];

    /** @var array<int, array{reactions: array<int, array{type: string, count: int}>, count: int, mine: ?string}> */
    private array $commentReactions = [];

    /**
     * Newest first. `feed` is `all` (everything you may see), `society` (your friends), `clubs` (your clubs and
     * communities) or `mine`; `user` and `club` narrow it to one person or one club.
     */
    public function index(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $data = $request->validate([
            'feed' => ['sometimes', Rule::in(['all', 'society', 'clubs', 'mine'])],
            'user' => ['sometimes', 'integer', 'min:1'],
            'club' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $query = Short::query()->forViewer($viewerId);

        match ($data['feed'] ?? 'all') {
            'society' => $query->whereIn('shorts.user_id', Friendship::friendIdsOf($viewerId) ?: [0]),
            'clubs' => $query->where('shorts.audience', Short::CLUB),
            'mine' => $query->where('shorts.user_id', $viewerId),
            default => null,
        };

        if (isset($data['user'])) {
            $query->where('shorts.user_id', $data['user']);
        }

        if (isset($data['club'])) {
            $club = Club::query()->findOrFail($data['club']);
            if (! $club->hasMember($viewerId)) {
                return response()->json(['message' => 'Join '.$club->name.' to see its shorts.'], 403);
            }
            $query->where('shorts.club_id', $club->id);
        }

        $page = $query->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 8));
        $this->loadReactions(collect($page->items())->pluck('id')->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (Short $short) => $this->transform($short, $viewerId))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(int $short): JsonResponse
    {
        $viewerId = $this->viewerId();
        $found = Short::query()->forViewer($viewerId)->find($short);

        if (! $found) {
            return response()->json(['message' => 'This short isn’t available. It may have been deleted or shared with a smaller group.'], 404);
        }

        return response()->json(['short' => $this->transform($found, $viewerId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $isVideo = $request->input('kind', Short::VIDEO) === Short::VIDEO;
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(Short::KINDS)],
            'video' => $isVideo
                ? ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-m4v,video/3gpp', 'max:'.Short::MAX_KB]
                : ['exclude'],
            'poster' => $isVideo ? ['nullable', 'image', 'max:5120'] : ['exclude'],
            'image' => $isVideo ? ['exclude'] : ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.Short::MAX_IMAGE_KB],
            'caption' => ['nullable', 'string', 'max:500'],
            'audience' => ['required', Rule::in(Short::AUDIENCES)],
            'club_id' => ['nullable', 'required_if:audience,'.Short::CLUB, 'integer', 'exists:clubs,id'],
            // Measured by the browser; there's no video tooling on the server to check it.
            'duration' => $isVideo ? ['required', 'numeric', 'min:0.5', 'max:'.(Short::MAX_SECONDS + 0.5)] : ['exclude'],
            'width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ], [
            'kind.in' => 'Choose a video or a photo.',
            'video.required' => 'Choose a video for your short.',
            'video.mimetypes' => 'Shorts can be MP4, MOV or WebM videos.',
            'video.max' => 'Videos can be up to 40 MB.',
            'video.uploaded' => 'The video didn’t upload. Videos can be up to 40 MB.',
            'image.required' => 'Choose a photo for your short.',
            'image.image' => 'Photos can be JPG, PNG, WebP or GIF.',
            'image.mimes' => 'Photos can be JPG, PNG, WebP or GIF.',
            'image.max' => 'Photos can be up to 10 MB.',
            'image.uploaded' => 'The photo didn’t upload. Photos can be up to 10 MB.',
            'audience.required' => 'Choose who can see your short.',
            'club_id.required_if' => 'Choose a club or community.',
            'duration.max' => 'Shorts can be up to '.Short::MAX_SECONDS.' seconds long.',
            'duration.min' => 'That video is too short.',
        ]);

        $club = null;
        if ($data['audience'] === Short::CLUB) {
            $club = Club::query()->findOrFail($data['club_id']);
            if (! $club->hasMember($viewerId)) {
                return response()->json(['message' => 'Join '.$club->name.' to share shorts there.'], 403);
            }
        }

        $caption = trim((string) ($data['caption'] ?? ''));

        $short = Short::query()->create([
            'user_id' => $viewerId,
            'club_id' => $club?->id,
            'audience' => $data['audience'],
            'caption' => $caption === '' ? null : $caption,
            'kind' => $isVideo ? Short::VIDEO : Short::IMAGE,
            'video_path' => $isVideo ? $request->file('video')->store('shorts', 'media') : null,
            'poster_path' => $isVideo ? $request->file('poster')?->store('shorts/posters', 'media') : null,
            'image_path' => $isVideo ? null : $request->file('image')->store('shorts/photos', 'media'),
            'duration_ms' => $isVideo ? (int) round((float) $data['duration'] * 1000) : null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
        ]);

        $message = match ($short->audience) {
            Short::SOCIETY => 'Short shared with your society.',
            Short::CLUB => 'Short shared to '.$club->name.'.',
            default => 'Short posted.',
        };

        return response()->json([
            'message' => $message,
            'short' => $this->transform(Short::query()->forViewer($viewerId)->findOrFail($short->id), $viewerId),
        ], 201);
    }

    public function destroy(Short $short): JsonResponse
    {
        if ((int) $short->user_id !== $this->viewerId()) {
            return response()->json(['message' => 'You can only delete your own shorts.'], 403);
        }

        Storage::disk('media')->delete(array_filter([$short->video_path, $short->poster_path, $short->image_path]));
        $short->delete();

        return response()->json(['message' => 'Short deleted.', 'short_id' => $short->id]);
    }

    /** Count a view. Each person counts once, and authors watching their own shorts don't count. */
    public function view(Short $short): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        if ((int) $short->user_id !== $viewerId) {
            ShortView::query()->insertOrIgnore([
                'short_id' => $short->id,
                'user_id' => $viewerId,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'short_id' => $short->id,
            'views_count' => $short->views()->count(),
        ]);
    }

    /** React to a short (a plain like by default); reacting again with another reaction switches it. */
    public function like(Request $request, Short $short): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        $data = $request->validate([
            'reaction' => ['sometimes', Rule::in(PostLike::REACTIONS)],
        ], [
            'reaction.in' => 'Pick one of the reactions.',
        ]);
        $reaction = $data['reaction'] ?? 'like';

        ShortLike::query()->updateOrCreate(
            ['short_id' => $short->id, 'user_id' => $this->viewerId()],
            ['reaction' => $reaction],
        );

        return $this->likeResponse($short, $reaction);
    }

    public function unlike(Short $short): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        $short->likes()->where('user_id', $this->viewerId())->delete();

        return $this->likeResponse($short, null);
    }

    /** Who reacted, newest first (200 max), with a count per reaction. */
    public function reactions(Short $short): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        $likes = $short->likes()->with('user')->latest('updated_at')->limit(200)->get();
        $this->loadReactions([$short->id]);

        return response()->json([
            'data' => $likes->map(fn (ShortLike $like) => [
                'user' => UserPresenter::author($like->user),
                'reaction' => $like->reaction,
                'is_me' => (int) $like->user_id === $viewerId,
            ]),
            'reactions' => $this->reactionSummary[$short->id],
            'total' => $short->likes()->count(),
        ]);
    }

    public function comments(Short $short): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        $comments = $short->comments()->with('user')->orderBy('id')->limit(200)->get();
        $this->loadCommentReactions($comments->modelKeys(), $viewerId);

        return response()->json([
            'data' => $comments->map(fn (ShortComment $comment) => $this->transformComment($comment, $viewerId, $short)),
        ]);
    }

    public function storeComment(Request $request, Short $short): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($short)) {
            return $denied;
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ], [
            'body.required' => 'Write a comment first.',
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Write a comment first.', 'errors' => ['body' => ['Write a comment first.']]], 422);
        }

        $comment = $short->comments()->create(['user_id' => $viewerId, 'body' => $body]);
        $comment->load('user');

        return response()->json([
            'message' => 'Comment added.',
            'comment' => $this->transformComment($comment, $viewerId, $short),
            'comments_count' => $short->comments()->count(),
        ], 201);
    }

    public function destroyComment(ShortComment $comment): JsonResponse
    {
        $viewerId = $this->viewerId();
        $short = $comment->short;

        if ((int) $comment->user_id !== $viewerId && (int) $short->user_id !== $viewerId) {
            return response()->json(['message' => 'You can only delete your own comments.'], 403);
        }

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted.',
            'short_id' => $short->id,
            'comments_count' => $short->comments()->count(),
        ]);
    }

    /** React to a comment (a plain like by default); reacting again with another reaction switches it. */
    public function likeComment(Request $request, ShortComment $comment): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($comment->short)) {
            return $denied;
        }

        $data = $request->validate([
            'reaction' => ['sometimes', Rule::in(PostLike::REACTIONS)],
        ], [
            'reaction.in' => 'Pick one of the reactions.',
        ]);

        ShortCommentLike::query()->updateOrCreate(
            ['short_comment_id' => $comment->id, 'user_id' => $this->viewerId()],
            ['reaction' => $data['reaction'] ?? 'like'],
        );

        return $this->commentLikeResponse($comment);
    }

    public function unlikeComment(ShortComment $comment): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($comment->short)) {
            return $denied;
        }

        $comment->likes()->where('user_id', $this->viewerId())->delete();

        return $this->commentLikeResponse($comment);
    }

    /** Who reacted to a comment, newest first (200 max), with a count per reaction. */
    public function commentReactions(ShortComment $comment): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessVisible($comment->short)) {
            return $denied;
        }

        $likes = $comment->likes()->with('user')->latest('updated_at')->limit(200)->get();
        $this->loadCommentReactions([$comment->id], $viewerId);

        return response()->json([
            'data' => $likes->map(fn (ShortCommentLike $like) => [
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

        $rows = ShortCommentLike::query()
            ->whereIn('short_comment_id', $ids)
            ->selectRaw('short_comment_id, reaction, COUNT(*) as total')
            ->groupBy('short_comment_id', 'reaction')
            ->get()
            ->groupBy('short_comment_id');
        $mine = ShortCommentLike::query()
            ->whereIn('short_comment_id', $ids)
            ->where('user_id', $viewerId)
            ->pluck('reaction', 'short_comment_id');

        foreach ($ids as $id) {
            $reactions = Reactions::sorted($rows->get($id) ?? collect());
            $this->commentReactions[$id] = [
                'reactions' => $reactions,
                'count' => array_sum(array_column($reactions, 'count')),
                'mine' => $mine->get($id),
            ];
        }
    }

    private function commentLikeResponse(ShortComment $comment): JsonResponse
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

    private function denyUnlessVisible(Short $short): ?JsonResponse
    {
        return $short->isVisibleTo($this->viewerId()) ? null : response()->json(['message' => 'Short not found.'], 404);
    }

    private function likeResponse(Short $short, ?string $reaction): JsonResponse
    {
        unset($this->reactionSummary[$short->id]);
        $this->loadReactions([$short->id]);

        return response()->json([
            'short_id' => $short->id,
            'my_reaction' => $reaction,
            'likes_count' => $short->likes()->count(),
            'reactions' => $this->reactionSummary[$short->id],
        ]);
    }

    /**
     * Reaction counts per short, most used first, in one query.
     *
     * @param  array<int, int>  $ids
     */
    private function loadReactions(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->reactionSummary)));
        if ($ids === []) {
            return;
        }

        $rows = ShortLike::query()
            ->whereIn('short_id', $ids)
            ->selectRaw('short_id, reaction, COUNT(*) as total')
            ->groupBy('short_id', 'reaction')
            ->get()
            ->groupBy('short_id');

        foreach ($ids as $id) {
            $this->reactionSummary[$id] = Reactions::sorted($rows->get($id) ?? collect());
        }
    }

    private function transform(Short $short, int $viewerId): array
    {
        $this->loadReactions([$short->id]);
        $media = Storage::disk('media');

        return [
            'id' => $short->id,
            'kind' => $short->kind,
            'caption' => $short->caption,
            'video_url' => $short->video_path ? $media->url($short->video_path) : null,
            'poster_url' => $short->poster_path ? $media->url($short->poster_path) : null,
            'image_url' => $short->image_path ? $media->url($short->image_path) : null,
            'duration' => $short->duration_ms !== null ? round($short->duration_ms / 1000, 1) : null,
            'width' => $short->width,
            'height' => $short->height,
            'audience' => $short->audience,
            'club' => $short->club ? ClubPresenter::summary($short->club) : null,
            'author' => UserPresenter::author($short->user),
            'is_mine' => (int) $short->user_id === $viewerId,
            'created_at' => $short->created_at?->toIso8601String(),
            'views_count' => (int) ($short->views_count ?? 0),
            'likes_count' => (int) ($short->likes_count ?? 0),
            'comments_count' => (int) ($short->comments_count ?? 0),
            'my_reaction' => $short->my_reaction,
            'reactions' => $this->reactionSummary[$short->id],
        ];
    }

    private function transformComment(ShortComment $comment, int $viewerId, Short $short): array
    {
        $reactions = $this->commentReactions[$comment->id] ?? ['reactions' => [], 'count' => 0, 'mine' => null];

        return [
            'id' => $comment->id,
            'short_id' => $comment->short_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
            'author' => UserPresenter::author($comment->user),
            'can_delete' => (int) $comment->user_id === $viewerId || (int) $short->user_id === $viewerId,
            'likes_count' => $reactions['count'],
            'my_reaction' => $reactions['mine'],
            'reactions' => $reactions['reactions'],
        ];
    }
}
