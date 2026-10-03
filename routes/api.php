<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubActivityController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubPositionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiaryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PersonalActivityController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShortController;
use App\Http\Controllers\SocietyController;
use App\Http\Controllers\SocietyGroupController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Modular feature routes live under routes/routes/*.php and should be
| included here as modules are added.
|
*/

Route::get('/', function () {
    return response()->json([
        'ok' => true,
        'service' => 'denuwe-api',
        'name' => 'denuwe',
        'version' => '1.0.0',
        'health' => url('/api/health'),
        'v1' => url('/api/v1'),
    ]);
});

Route::get('/health', function () {
    $database = [
        'status' => 'ok',
        'connection' => config('database.default'),
        'name' => null,
        'version' => null,
        'latency_ms' => null,
    ];

    try {
        $started = microtime(true);
        $pdo = DB::connection()->getPdo();
        DB::select('select 1');
        $database['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
        $database['name'] = DB::connection()->getDatabaseName();
        $database['version'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable $e) {
        $database['status'] = 'error';
        $database['error'] = config('app.debug') ? $e->getMessage() : 'Database connection failed';
    }

    $ok = $database['status'] === 'ok';

    return response()->json([
        'ok' => $ok,
        'service' => 'denuwe-api',
        'status' => $ok ? 'healthy' : 'degraded',
        'app' => config('app.name'),
        'env' => config('app.env'),
        'database' => $database,
        'time' => now()->toIso8601String(),
        'php' => PHP_VERSION,
        'laravel' => app()->version(),
    ], $ok ? 200 : 503);
});

Route::prefix('v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'ok' => true,
            'service' => 'denuwe-api',
            'version' => 'v1',
            'health' => url('/api/health'),
            'auth' => [
                'register' => url('/api/v1/auth/register'),
                'login' => url('/api/v1/auth/login'),
                'me' => url('/api/v1/auth/me'),
                'logout' => url('/api/v1/auth/logout'),
                'refresh' => url('/api/v1/auth/refresh'),
            ],
            'posts' => url('/api/v1/posts'),
            'conversations' => url('/api/v1/conversations'),
        ]);
    });

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
    });

    Route::middleware('auth:api')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::patch('profile', [ProfileController::class, 'update']);
        Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('profile/avatar', [ProfileController::class, 'removeAvatar']);
        Route::post('profile/banner', [ProfileController::class, 'uploadBanner']);
        Route::delete('profile/banner', [ProfileController::class, 'removeBanner']);

        Route::get('diary', [DiaryController::class, 'index']);
        Route::post('diary', [DiaryController::class, 'store']);
        Route::patch('diary/{entry}', [DiaryController::class, 'update'])->whereNumber('entry');
        Route::delete('diary/{entry}', [DiaryController::class, 'destroy'])->whereNumber('entry');

        Route::get('users/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
        Route::get('users/{user}/diary', [DiaryController::class, 'forUser'])->whereNumber('user');
        Route::get('users/{user}/posts', [PostController::class, 'byUser'])->whereNumber('user');

        Route::get('clubs', [ClubController::class, 'index']);
        Route::post('clubs', [ClubController::class, 'store']);
        Route::get('clubs/mine', [ClubController::class, 'mine']);
        Route::get('clubs/{club}', [ClubController::class, 'show']);
        Route::patch('clubs/{club}', [ClubController::class, 'update']);
        Route::delete('clubs/{club}', [ClubController::class, 'destroy']);
        Route::post('clubs/{club}/avatar', [ClubController::class, 'uploadAvatar']);
        Route::delete('clubs/{club}/avatar', [ClubController::class, 'removeAvatar']);
        Route::post('clubs/{club}/banner', [ClubController::class, 'uploadBanner']);
        Route::delete('clubs/{club}/banner', [ClubController::class, 'removeBanner']);
        Route::post('clubs/{club}/join', [ClubController::class, 'join']);
        Route::delete('clubs/{club}/join', [ClubController::class, 'leave']);
        Route::post('clubs/{club}/requests/{user}', [ClubController::class, 'approveRequest']);
        Route::delete('clubs/{club}/requests/{user}', [ClubController::class, 'declineRequest']);
        Route::patch('clubs/{club}/members/{user}', [ClubController::class, 'updateMemberFee']);
        Route::put('clubs/{club}/members/{user}/position', [ClubPositionController::class, 'assign']);
        Route::post('clubs/{club}/positions', [ClubPositionController::class, 'store']);
        Route::put('clubs/{club}/positions/order', [ClubPositionController::class, 'reorder']);
        Route::patch('clubs/{club}/positions/{position}', [ClubPositionController::class, 'update'])->whereNumber('position');
        Route::delete('clubs/{club}/positions/{position}', [ClubPositionController::class, 'destroy'])->whereNumber('position');
        Route::get('clubs/{club}/posts', [PostController::class, 'wall']);
        Route::post('clubs/{club}/activities', [ClubActivityController::class, 'store']);
        Route::get('tournaments', [TournamentController::class, 'index']);
        Route::post('tournaments', [TournamentController::class, 'store']);
        Route::get('tournaments/{tournament}', [TournamentController::class, 'show']);
        Route::patch('tournaments/{tournament}', [TournamentController::class, 'update'])->whereNumber('tournament');
        Route::delete('tournaments/{tournament}', [TournamentController::class, 'destroy'])->whereNumber('tournament');
        Route::post('tournaments/{tournament}/brackets', [TournamentController::class, 'storeBracket'])->whereNumber('tournament');
        Route::patch('tournaments/{tournament}/brackets/{bracket}', [TournamentController::class, 'updateBracket'])->whereNumber(['tournament', 'bracket']);
        Route::delete('tournaments/{tournament}/brackets/{bracket}', [TournamentController::class, 'destroyBracket'])->whereNumber(['tournament', 'bracket']);
        Route::put('tournaments/{tournament}/brackets/{bracket}/draw', [TournamentController::class, 'bracketDraw'])->whereNumber(['tournament', 'bracket']);
        Route::put('tournaments/{tournament}/brackets/{bracket}/round-names', [TournamentController::class, 'bracketRoundNames'])->whereNumber(['tournament', 'bracket']);
        Route::post('tournaments/{tournament}/{type}', [TournamentController::class, 'uploadMedia'])->whereNumber('tournament')->whereIn('type', ['avatar', 'banner']);
        Route::delete('tournaments/{tournament}/{type}', [TournamentController::class, 'removeMedia'])->whereNumber('tournament')->whereIn('type', ['avatar', 'banner']);
        Route::post('tournaments/{tournament}/invites', [TournamentController::class, 'invite'])->whereNumber('tournament');
        Route::delete('tournaments/{tournament}/invites/{user}', [TournamentController::class, 'uninvite'])->whereNumber(['tournament', 'user']);
        Route::post('tournaments/{tournament}/join', [TournamentController::class, 'join'])->whereNumber('tournament');
        Route::delete('tournaments/{tournament}/join', [TournamentController::class, 'leave'])->whereNumber('tournament');
        Route::delete('tournaments/{tournament}/entries/{entry}', [TournamentController::class, 'removeEntry'])->whereNumber(['tournament', 'entry']);
        Route::post('tournaments/{tournament}/start', [TournamentController::class, 'start'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/group-stage', [TournamentController::class, 'groupStage'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/advancing', [TournamentController::class, 'advancing'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/group-slots', [TournamentController::class, 'groupSlots'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/schedule', [TournamentController::class, 'schedule'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/schedule/move', [TournamentController::class, 'moveGame'])->whereNumber('tournament');
        Route::post('tournaments/{tournament}/reset', [TournamentController::class, 'reset'])->whereNumber('tournament');
        Route::put('tournaments/{tournament}/matches/{match}', [TournamentController::class, 'recordMatch'])->whereNumber(['tournament', 'match']);
        Route::delete('tournaments/{tournament}/matches/{match}', [TournamentController::class, 'clearMatch'])->whereNumber(['tournament', 'match']);
        Route::get('activities', [ClubActivityController::class, 'index']);
        Route::get('activities/upcoming', [ClubActivityController::class, 'upcoming']);
        Route::delete('activities/{activity}', [ClubActivityController::class, 'destroy']);
        Route::put('activities/{activity}/response', [ClubActivityController::class, 'respond']);
        Route::post('personal-activities', [PersonalActivityController::class, 'store']);
        Route::put('personal-activities/{activity}', [PersonalActivityController::class, 'update'])->whereNumber('activity');
        Route::delete('personal-activities/{activity}', [PersonalActivityController::class, 'destroy'])->whereNumber('activity');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'markRead']);

        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store'])->middleware('throttle:60,1');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])
            ->whereNumber('conversation');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->whereNumber('conversation')
            ->middleware('throttle:60,1');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])
            ->whereNumber('conversation')
            ->middleware('throttle:120,1');

        Route::get('society/groups', [SocietyGroupController::class, 'index']);
        Route::post('society/groups', [SocietyGroupController::class, 'store'])->middleware('throttle:20,1');
        Route::get('society/groups/{group}', [SocietyGroupController::class, 'show'])->whereNumber('group');
        Route::patch('society/groups/{group}', [SocietyGroupController::class, 'update'])->whereNumber('group');
        Route::delete('society/groups/{group}', [SocietyGroupController::class, 'destroy'])->whereNumber('group');
        Route::post('society/groups/{group}/members', [SocietyGroupController::class, 'addMembers'])->whereNumber('group');
        Route::delete('society/groups/{group}/members/{user}', [SocietyGroupController::class, 'removeMember'])
            ->whereNumber(['group', 'user']);
        Route::get('society/groups/{group}/messages', [SocietyGroupController::class, 'messages'])->whereNumber('group');
        Route::post('society/groups/{group}/messages', [SocietyGroupController::class, 'sendMessage'])
            ->whereNumber('group')
            ->middleware('throttle:60,1');

        Route::get('society', [SocietyController::class, 'index']);
        Route::get('society/people', [SocietyController::class, 'people']);
        Route::post('society/{user}', [SocietyController::class, 'store'])->whereNumber('user');
        Route::post('society/{user}/accept', [SocietyController::class, 'accept'])->whereNumber('user');
        Route::delete('society/{user}', [SocietyController::class, 'destroy'])->whereNumber('user');
        Route::get('society/{user}/messages', [SocietyController::class, 'messages'])->whereNumber('user');
        Route::post('society/{user}/messages', [SocietyController::class, 'sendMessage'])
            ->whereNumber('user')
            ->middleware('throttle:60,1');

        Route::get('posts', [PostController::class, 'index']);
        Route::post('posts', [PostController::class, 'store']);
        Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post');
        Route::delete('posts/{post}', [PostController::class, 'destroy']);
        Route::post('posts/{post}/like', [PostController::class, 'like']);
        Route::delete('posts/{post}/like', [PostController::class, 'unlike']);
        Route::get('posts/{post}/reactions', [PostController::class, 'reactions']);
        Route::post('posts/{post}/repost', [PostController::class, 'repost']);
        Route::delete('posts/{post}/repost', [PostController::class, 'undoRepost']);
        Route::get('posts/{post}/comments', [PostController::class, 'comments']);
        Route::post('posts/{post}/comments', [PostController::class, 'storeComment']);
        Route::delete('comments/{comment}', [PostController::class, 'destroyComment']);
        Route::post('comments/{comment}/like', [PostController::class, 'likeComment']);
        Route::delete('comments/{comment}/like', [PostController::class, 'unlikeComment']);
        Route::get('comments/{comment}/reactions', [PostController::class, 'commentReactions']);

        Route::get('shorts', [ShortController::class, 'index']);
        Route::post('shorts', [ShortController::class, 'store'])->middleware('throttle:20,1');
        Route::get('shorts/{short}', [ShortController::class, 'show'])->whereNumber('short');
        Route::delete('shorts/{short}', [ShortController::class, 'destroy'])->whereNumber('short');
        Route::post('shorts/{short}/view', [ShortController::class, 'view'])->whereNumber('short');
        Route::post('shorts/{short}/like', [ShortController::class, 'like'])->whereNumber('short');
        Route::delete('shorts/{short}/like', [ShortController::class, 'unlike'])->whereNumber('short');
        Route::get('shorts/{short}/reactions', [ShortController::class, 'reactions'])->whereNumber('short');
        Route::get('shorts/{short}/comments', [ShortController::class, 'comments'])->whereNumber('short');
        Route::post('shorts/{short}/comments', [ShortController::class, 'storeComment'])->whereNumber('short');
        Route::delete('short-comments/{comment}', [ShortController::class, 'destroyComment'])->whereNumber('comment');
        Route::post('short-comments/{comment}/like', [ShortController::class, 'likeComment'])->whereNumber('comment');
        Route::delete('short-comments/{comment}/like', [ShortController::class, 'unlikeComment'])->whereNumber('comment');
        Route::get('short-comments/{comment}/reactions', [ShortController::class, 'commentReactions'])->whereNumber('comment');
    });
});
