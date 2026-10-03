# denuwe API (`denuwe-ws`)

Laravel backend for denuwe. Foundation mirrors LPay (`lpay-ws`).

## Quick start (XAMPP MySQL)

1. Start Apache + MySQL in XAMPP.
2. Create database `viclub` (phpMyAdmin or CLI).
3. Install and run:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan serve
php artisan reverb:start   # in a second terminal, for real-time messaging
```

- API root: http://localhost:8000/api  
- Health: http://localhost:8000/api/health  
- WebSockets: ws://127.0.0.1:8080 (see [Messaging](#messaging-real-time-1-to-1))

## User auth (JWT)

All under `/api/v1`. Send `Accept: application/json`; protected routes need `Authorization: Bearer <access_token>`.

| Method | Path | Auth | Body |
|--------|------|------|------|
| POST | `/auth/register` | — | `first_name`, `last_name`, `birthday` (`YYYY-MM-DD`, 13+), `gender` (`female` / `male` / `prefer_not_to_say`), `contact` (email or mobile number), `password` (min 8) |
| POST | `/auth/login` | — | `identifier` (email or mobile number), `password` |
| POST | `/auth/refresh` | Bearer (may be expired, within refresh window) | — |
| GET | `/auth/me` | Bearer | — |
| POST | `/auth/logout` | Bearer | — |

Register, login and refresh return `{ message, access_token, token_type, expires_in, user }`.
Validation errors return `422` with `errors` keyed by field (email/phone problems are reported on `contact`).
Register, login and refresh are limited to 10 requests per minute per IP.

## Profile

All under `/api/v1`, Bearer auth required. Each returns `{ message, user }`.

| Method | Path | Body |
|--------|------|------|
| PATCH | `/profile` | `first_name`, `last_name` (required), `headline` (160), `pronouns` (32), `location` (120), `bio` (2000), `website` (URL; `https://` added if missing), `contact_email`, `contact_phone` (7–15 digits, optional leading `+`; spaces and dashes removed) — contact details shown on your profile to your society, separate from the `email`/`phone` you sign in with |
| POST / DELETE | `/profile/avatar` | multipart `image` (max 5 MB) when uploading |
| POST / DELETE | `/profile/banner` | multipart `image` (max 8 MB) when uploading |
| PATCH | `/profile/background` | `background` (`none`, a template id or `photo`), optional `effect` (`natural`, `soft`, `frosted`, `duotone`, `dark`) |
| POST / DELETE | `/profile/background` | multipart `image` (max 8 MB) and optional `effect`; uploading makes the photo the background |

## Diary

All under `/api/v1`, Bearer auth required. Only you can write to your diary; your society (accepted friends) can read it, nobody else. Write as many entries as you like for today or any past day.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/diary` | `search`, `cursor` — newest day first, 20 per page; also returns `total` and `dates` (days with an entry, for streaks) |
| POST | `/diary` | `entry_date` (Y-m-d, not in the future), `body` (10000), optional `title` (120), `mood` (`great`, `good`, `okay`, `low`, `bad`) |
| PATCH | `/diary/{entry}` | same fields as POST; your own entries only (others get 404) |
| DELETE | `/diary/{entry}` | your own entries only |
| GET | `/users/{user}/diary` | `search`, `cursor` — a friend's diary, same shape as `/diary`; 403 unless you're friends |

## People

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/users/{user}` | `{ user }` — name, avatar, banner, headline, location, `relationship`, `mutual_count`, `can_view`. When `can_view` (yourself or a friend) also `bio`, `website`, `pronouns`, `created_at`, `friends_count`, `diary_count`, `positions` |

## Clubs & activities

All under `/api/v1`, Bearer auth required.

Every club has a `slug` made from its name (`JCI Makati` → `jci-makati`; `-2`, `-3`… when taken). It changes when the club is renamed. Every club and community also gets a permanent `uuid` (UUIDv7) when it’s created; it never changes and is included wherever a club is returned, including club summaries. Wherever a path says `{id}` for a club, the slug or uuid works too; the app links to clubs as `/clubs/{slug}` and communities as `/communities/{slug}`.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/clubs` | `search` — discover clubs, most members first |
| GET | `/clubs/mine` | — clubs you belong to; `my_position` is your position name there, or `null` |
| POST | `/clubs` | `type` (`club` / `community`), `name` (80), `description` (500), `color` (`emerald` / `blue` / `pink` / `ink` / `amber` / `purple`), `membership` (`free` / `paid`); when paid also `fee_amount` (1–1,000,000), `fee_currency` (`PHP` / `USD`), `fee_period` (`one_time` / `monthly` / `yearly`) — creator becomes owner |
| GET | `/clubs/{id}` | — club, members, `positions` (`id`, `name`, `can_organize`, `organize_locked`, `holders_count`), upcoming activities, `tournaments` (summaries, live and open first, then the 10 most recently completed) and `can_organize` (whether you can create activities and tournaments). Each member has a `position` (`id`, `name`) or `null`; officers are listed first in position order. Members’ `fee_status` is only shown to the owner (and to each member for themselves) |
| GET | `/clubs/{id}/posts` | `cursor` — the club’s wall (posts made with `club_id`), newest first, 10 per page. Members only (403 otherwise) |
| PATCH | `/clubs/{id}` | same fields as `POST /clubs` — owner only. Switching free → paid marks existing members `unpaid`; paid → free clears their `fee_status`. Switching private → public admits everyone with a pending join request |
| POST / DELETE | `/clubs/{id}/avatar` | `image` (multipart, max 5 MB) — upload / remove the profile picture, owner only |
| POST / DELETE | `/clubs/{id}/banner` | `image` (multipart, max 8 MB) — upload / remove the banner, owner only |
| DELETE | `/clubs/{id}` | — owner only (also deletes its images) |
| POST / DELETE | `/clubs/{id}/join` | — join / leave (owners can’t leave). Joining a paid club sets your `fee_status` to `unpaid`. On a private club, POST sends a join request (`has_requested: true`) and DELETE cancels it |
| POST / DELETE | `/clubs/{id}/requests/{userId}` | — approve / decline a join request; owner only |

**Privacy:** `POST`/`PATCH /clubs` accept `visibility` (`public`, the default, or `private`). Every club includes `visibility`, `has_requested` and `requests_count` (owner only, otherwise 0). Private clubs are left out of `GET /clubs` (Explore and search) and out of other people’s profile positions unless you’re a member; ones you’ve asked to join still show in `GET /clubs`. For non-members of a private club, `GET /clubs/{id}` (someone with the link) returns `can_view: false` with `description` and `banner_url` null, `members_count` and `upcoming_count` 0, and empty `members`, `positions`, `activities` and `tournaments`, so they can only ask to join; the wall returns 403. The owner also gets `join_requests` (people with `requested_at`).
| PATCH | `/clubs/{id}/members/{userId}` | `fee_status` (`paid` / `unpaid`) — owner only, paid clubs only |
| POST | `/clubs/{id}/positions` | `name` (60, unique per club), optional `can_organize` (defaults to on for “President” and “Vice President”) — add a custom position at the end of the list, owner only, max 30 |
| PATCH / DELETE | `/clubs/{id}/positions/{positionId}` | `name` and/or `can_organize` — rename, change whether holders can create activities and tournaments, or delete; owner only. Deleting clears it from its holders |
| PUT | `/clubs/{id}/positions/order` | `ids` (position ids in the new order) — owner only |
| PUT | `/clubs/{id}/members/{userId}/position` | `position_id` (or `null` to clear) — owner only; the user must be a member |
| POST | `/clubs/{id}/activities` | `title` (120), `starts_at` (ISO date-time, future), `location` (120), `description` (2000) — organizers only |
| GET | `/activities` | — the activities page across your clubs and your personal schedule: `upcoming` (from 24 hours ago onward, soonest first, up to 100 of each kind), `past` (older, newest first, up to 30 of each kind), `organize_clubs` (club summaries where you can add activities), `my_clubs` (every club and community you're in) and `clubs_count`. Each item has `kind`: `club` or `personal`; personal ones also have `club` (summary or `null` = personal), `is_meeting` and `meeting_url` |
| POST | `/personal-activities` | `title` (120), `club_id` (one of your clubs or communities; `null` = personal), `starts_at` (ISO date-time; past dates allowed), `all_day` (boolean), `ends_at` (after `starts_at`; ignored when `all_day`), `location` (120), `is_meeting` (boolean), `meeting_url` (http/https, 500; only kept for meetings), `description` (2000) — only you can see these, and you're going without a reply |
| PUT / DELETE | `/personal-activities/{id}` | same fields as `POST` — edit or delete one of your own; anyone else’s returns 404 |
| GET | `/activities/upcoming` | `limit` (default 5) — across your clubs, soonest first |
| DELETE | `/activities/{id}` | — creator or club owner |
| PUT | `/activities/{id}/response` | `status` (`going` or `not_going`) — club members only, until the activity starts; answering again changes it. Leaving a club clears your answers to its upcoming activities |

A personal activity has `kind: personal`, `id`, `title`, `description`, `location`, `starts_at`, `ends_at`, `all_day` and `has_started`.

Every club activity includes `has_started`, `going_count`, `not_going_count`, `going` and `not_going` (people, earliest answer first) and `my_response` (`going`, `not_going` or `null`).

**Organizers** are the owner, the holder of a position named “President” (always, whatever `can_organize` says; positions include `organize_locked: true` for it) and members whose position has `can_organize` on. Any member can post on the wall. Club tournaments live under [Tournaments](#tournaments).

## Tournaments

All under `/api/v1`, Bearer auth required. Anyone can host a **personal** tournament (invite only: people from the organizer’s society). Club organizers can host one **for a club** (`club_id`), open to its members; they can invite friends too. `visibility` is `private` (default, the rules above) or `public`: listed for everyone in `GET /tournaments` → `public`, and anyone can view and join. Switching back to `private` keeps the players already in. Entries are single players (`format: individual`) or teams (`format: team`, `team_size` 2–20). The bracket is `single_elimination` (seeds shuffled at start, byes go to the top seeds, the winner of each match moves on), `double_elimination` (a single-elimination upper bracket plus a lower bracket: a first loss drops you to the lower bracket, a second knocks you out) or `round_robin` (everyone plays everyone; 3 points a win, 1 a draw, then score difference, scores for, seed). `status` goes `registration` → `in_progress` (organizer starts it) → `completed` (every bracket has a champion / every round-robin match played).

**Brackets.** An elimination tournament can have several named brackets (e.g. "Men’s" and "Women’s", up to 8), each with its own size, players, matches and champion. While there are brackets, `max_entries` is their sizes added up (all together up to 128 slots); `PATCH` ignores `max_entries` then. With an elimination stage (below) `max_entries` stays independent, since more can enter than move on. Every entry and match has a `bracket_id` (`null` for round robin, and for entries not placed yet). `winner` is set when a one-bracket tournament finishes; `champions` lists each bracket’s winner either way.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/tournaments` | — `data`: tournaments you organize, play in, were invited to or that your clubs host (live first, then open, then completed); `public`: every public tournament, same order, up to 100; `organize_clubs`: clubs you can host for |
| POST | `/tournaments` | `name` (required, 120), `format`, `team_size` (teams), `bracket`, `starts_at` (future), `game` (80), `location` (120), `prize` (120), `max_entries` (2–128, round robin 2–32, empty = no limit), `description` (2000), `visibility` (`public` or `private`, default `private`), optional `club_id` (organizers only, else 403) and `invite_ids` (only your friends are invited) |
| GET | `/tournaments/{id}` | `{id}` can also be the `slug` or `uuid` — the tournament page: summary plus `entries` (`name`, `team_name`, `seed`, `group_number`, `group_slot`, `advanced`, `captain_id`, `members`), `matches` (`bracket_id`, `group_number`, `side`, `round`, `position`, `entry1_id`, `entry2_id`, `score1`, `score2`, `winner_id`, `completed`, `is_bye`), `rounds` (round robin), `brackets` (in order: `id`, `name`, `size`, `rounds` (upper-bracket rounds), `draw` (an entry id or `null` per first-round slot, its length being `size`), `opening` (the first-round spots that play an opening match), `round_names` (custom titles from the final back, `[]` for none), `winner_id`), `standings` (round robin), `overall_standings` (every result so far, elimination stage and brackets together, ranked like round robin; byes don't count; `[]` until a match is played), `groups` (elimination stage, once started: `number`, `name` ("Group A"), `entry_ids`, `rounds`, `standings`), `group_map` (elimination stage, before the start: the match map by slot), `schedule` (game setup, below), `invites` (organizers only), `invites_count`, `invited_by`. Personal tournaments are 403 for people who aren’t invited or playing |
| PATCH | `/tournaments/{id}` | same fields as `POST` except `club_id`/`invite_ids` — organizer, before the start. Can’t switch format once someone entered, shrink `team_size` below a team or `max_entries` below the entries. Switching to `round_robin` deletes the brackets and turns off the elimination stage |
| DELETE | `/tournaments/{id}` | — organizer (creator, or the host club’s owner); its profile picture and banner are deleted too |
| POST | `/tournaments/{id}/avatar` | multipart `image` (jpg, png, gif, webp; up to 5 MB) — organizer, any time; replaces the old profile picture |
| POST | `/tournaments/{id}/banner` | multipart `image` (jpg, png, gif, webp; up to 8 MB) — organizer, any time; replaces the old banner |
| DELETE | `/tournaments/{id}/avatar` or `/banner` | — organizer removes the picture |
| POST | `/tournaments/{id}/invites` | `user_ids` — organizer; only friends who aren’t invited or playing yet |
| DELETE | `/tournaments/{id}/invites/{userId}` | — organizer withdraws (and removes them if they can’t enter otherwise), or your own id to decline |
| POST | `/tournaments/{id}/join` | individual: nothing. Team: `team_name` (80, unique) to start a team as captain, or `entry_id` to join one with room. Invited people, club members and the organizer; before the start |
| DELETE | `/tournaments/{id}/join` | — leave before the start; an empty team is removed, a leaving captain hands over |
| DELETE | `/tournaments/{id}/entries/{entryId}` | — organizer removes a player or team before the start |
| POST | `/tournaments/{id}/brackets` | `name` (required, 60, unique in the tournament ignoring case), `size` (2–128, within the slots left) — organizer, single or double elimination, before the start. Creates an empty bracket (even before anyone joins) and sets `max_entries` to every bracket’s size added up |
| PATCH | `/tournaments/{id}/brackets/{bracketId}` | `name`, `size` — organizer. Renaming works any time; the size only before the start (shrinking unplaces anyone in a slot that’s gone and resets `opening`) |
| DELETE | `/tournaments/{id}/brackets/{bracketId}` | — organizer, any time; its players become unplaced and `max_entries` drops by its size. After the start it first resets the tournament (every match and result goes, registration reopens; after an elimination stage only the bracket results go and it's back in the groups) |
| PUT | `/tournaments/{id}/brackets/{bracketId}/draw` | `slots`: an entry id or `null` for each first-round slot, top to bottom, exactly `size` long — organizer, before the start. Placing someone who’s in another bracket moves them here. Sizes that aren’t a power of two have byes: optional `opening` lists which first-round spots (pair indexes, 0 = top) play an opening match, the rest put one player straight into the next round. It needs exactly `size − nextPowerOfTwo/2` distinct spots (10 slots → 2 of 8); left out, they’re spread out like seeds past the field (10 slots → spots 1 and 5). Slots are numbered top to bottom, two for an opening match and one for a bye. People who join later are unplaced until the organizer puts them in. 422 for the wrong length, an entry that isn’t in the tournament or appears twice |
| PUT | `/tournaments/{id}/brackets/{bracketId}/round-names` | `names`: up to 7 titles (40 chars) counted back from the final (0 = final, 1 = semifinals …; in double elimination 0 = upper bracket final); `null` or blank keeps the usual name — organizer, any time |
| PUT | `/tournaments/{id}/group-stage` | `group_stage` (`null` = off, `single` or `double` round robin), `group_count` (1–8) — organizer, single or double elimination, before the start. Unplaces everyone from the brackets |
| PUT | `/tournaments/{id}/schedule` | `minutes` (5–600), `days` (up to 60: `date` `Y-m-d`, each its own, `start` `H:i`, `games` 1–200; `[]` clears them) — organizer, any time. Games moved to a day that's gone go back in order |
| PUT | `/tournaments/{id}/schedule/move` | `key` (a game in `schedule.games`), `date` (one of the days, or `null` for back in order) — organizer. 422 for an unknown game, a date that isn't a game day, or a day whose `games` are all moved games already |
| PUT | `/tournaments/{id}/group-slots` | `slots`: an entry id or `null` for each match-map slot, exactly `group_map.slots` long — organizer, with an elimination stage, before the start. 422 for the wrong length, someone in two slots, or an entry not in the tournament. Turning the stage off clears every `group_slot`; lowering Max players clears those past it; reset keeps them |
| PUT | `/tournaments/{id}/advancing` | `entry_ids`: everyone moving on to the bracket (can be empty) — organizer, during the elimination stage. Anyone left out is taken off the brackets |
| POST | `/tournaments/{id}/start` | — organizer; needs 2+ entries. With an elimination stage the first start begins the groups (needs 2 entries a group) and the second starts the bracket with the entries marked `advanced` (needs 2+). Elimination: placed entries stay put; the rest first fill first-round matches that would have no one (in any bracket), then random open slots in any bracket. 422 if the entries don’t fit the brackets, or a first-round match would still be empty (e.g. 7 players in a 10-slot bracket, which needs 8). With no brackets, a "Main bracket" is created with a slot per entry in random order. Round robin shuffles the seeds. Creates the matches and closes registration |
| POST | `/tournaments/{id}/reset` | — organizer; deletes every match and bracket champion and reopens registration. The brackets and their draws are kept, so they can be tweaked and started again. With an elimination stage it steps back one stage: from the bracket to the groups (group results and picks kept), or from the groups to registration (groups, picks and placements cleared) |
| PUT | `/tournaments/{id}/matches/{matchId}` | `winner_id` (an entry in the match; `null` = draw, round robin and group matches only), `score1`/`score2` (both or neither, 0–9999; the winner needs the higher score unless tied) — organizer. Changing a winner after the next match is played returns 422 (double elimination: "A later match already has a result. Clear it first.", for the match the winner or the loser went to) |
| DELETE | `/tournaments/{id}/matches/{matchId}` | — organizer clears a result (not byes, not when the next match is played) |

**Double elimination.** Each bracket gets its own upper bracket, lower bracket and grand final. Every match has a `side`: `winners` (upper bracket), `losers` (lower bracket) or `final`. The upper bracket is laid out like single elimination (same draw, byes and opening matches). With `R` upper rounds the lower bracket has `2(R−1)` rounds: lower round 1 pairs the losers of upper round 1; each even round has the winners of the previous lower round face the players dropping from the next upper round (in reverse order, so rematches come late); each odd round after that pairs the survivors. A player whose opponent never comes (byes in the upper bracket) goes through on a bye; a match with nobody in it is closed with no winner. The `final` side has the grand final (round 1): the upper bracket champion (`entry1`) against the lower bracket champion (`entry2`). If `entry2` wins, both have lost once, so a reset match (round 2) is created; clearing that grand final result removes it. The bracket’s champion is the reset match winner, or the grand final winner when that's `entry1`. Results fill the lower bracket and grand final as they come in.

**Elimination stage.** Optional, for single and double elimination: `group_stage` `single` or `double` (round robin once or twice, the second time with sides swapped) in `group_count` groups. Before the start, `group_map` is the match map by slot: `slots` (Max players, or the entries so far with no limit), `from_max_entries`, and `groups` (`number`, `name`, `slots`, `rounds`, `matches`: `[{round, slot1, slot2}]`; empty when there can't be 2 slots a group). Slots fill the groups in order (9 slots in 2 groups: A = 1–5, B = 6–9; the first groups get the extra one) and round 1 pairs neighbours (slot 1 vs slot 2, slot 3 vs slot 4 …, the last slot resting when a group is odd); the circle method rotates from there, and double round robin repeats every round with sides swapped. It's `null` otherwise. Before the start the organizer can put entries in slots (`group_slot`, via `group-slots`). The first start keeps those, draws everyone else into random open slots, drops any slots still empty (everyone after them moves up), numbers them 1…n (their `seed`), splits them into groups the same way (`group_number`) and creates exactly that map's matches (`side: group`, `group_number`, `bracket_id` null; rounds are per group). `status` is `in_progress` with `stage: groups`. Group matches score like round robin (draws allowed) and can be changed only while in the groups. The organizer marks who moves on (`advanced`, via `advancing`); brackets can still be created, resized and drawn then, but only with advanced entries. The second start builds the brackets from the advanced entries (`stage: knockout`); the group matches stay. Without an elimination stage `stage` is `null`.

**Game setup.** `schedule` on the tournament page: `minutes` (a game's length, default 30), `days` (date order: `date` `Y-m-d`, `start` `H:i`, `games`), `games` and `bracket_pending` (an elimination stage with no bracket yet, so the bracket's games aren't listed). `games` is every game in playing order: the elimination stage round by round (groups side by side), then each bracket round by round, lower-bracket rounds as soon as the rounds feeding them are played, the grand final last; several brackets interleave. Byes aren't games. Before a stage starts its games come from the slot maps (`slot1`/`slot2`, `match_id` null, `entry1_id`/`entry2_id` when the organizer placed someone; later bracket rounds have no slots); afterwards they're the real matches (`match_id`, entries, scores, `winner_id`, `completed`). Each game has `key` (the same before and after the start, e.g. `group-g1-r2-0`, `winners-b0-r1-0` with `b0` the first bracket, `rr-r3-1`), `side`, `bracket_id`, `group_number`, `round`, `position`, `rounds` (its bracket's upper rounds, for titles), `date`, `time` (`null` when no day has room) and `moved`. Days fill in order: moved games sit on their day and count towards its `games`, the rest take the next free spot, and each day's games run back to back from `start`, `minutes` apart, in playing order. A moved game stays on its day after the start (if the start drops empty slots, games shift with their keys).

Every summary has `uuid` (UUIDv7, set on create, never changes), `slug` (from the name, e.g. `pickle-ball`; `-2`, `-3` … for repeats; follows renames), `status`, `visibility`, `stage` (`groups`, `knockout` or `null`), `group_stage` (`single`, `double` or `null`), `group_count`, `avatar_url` and `banner_url` (on the `media` disk, or `null`), `entries_count`, `players_count`, `is_full`, `club` (or `null`), `created_by`, `winner`, `champions` (`[{bracket, entry}]`, one per finished bracket), `my_entry_id`, `invite_pending`, `can_manage`, `can_enter` and `share_to`. Invites show in `GET /notifications` as `tournament_invite` with `url` `/tournaments/{slug}`.

## My Society (friends)

All under `/api/v1`, Bearer auth required. People carry `relationship`: `none`, `friends`, `incoming` (they asked you) or `outgoing` (you asked them), plus `mutual_count`.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/society` | — `friends`, `incoming` and `outgoing` requests |
| GET | `/society/people` | `search` — name, exact email or exact mobile number (30 max). Without a search: up to 24 suggestions you aren’t connected to, most mutual friends first |
| POST | `/society/{userId}` | — send a friend request (accepts theirs if they already asked you) |
| POST | `/society/{userId}/accept` | — accept their request |
| DELETE | `/society/{userId}` | — withdraw a sent request, ignore a received one, or remove a friend |
| GET | `/society/{userId}/messages` | — last 100 messages with a friend, oldest first; marks theirs as read. `last_read_id` is your latest message if they’ve seen it |
| POST | `/society/{userId}/messages` | `body` (2000) — friends only, 60 per minute |

`GET /society` friends also include `last_message`, `unread_count` and `conversation_id` (most recent conversation first), plus a total `unread_count`. The two `/society/{userId}/messages` routes are kept for older clients; they read and write the same conversations as [Messaging](#messaging-real-time-1-to-1).

### Group Society (group chats)

Group chats between friends, up to 50 people. Only members can see a group (404 otherwise). Any member can add their own friends, who join right away, and rename the group; anyone can leave. The owner can also remove people and delete the group; when the owner leaves, the longest-standing member takes over, and a group with no one left is deleted.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/society/groups` | — your groups (created or added to), most recent activity first, plus a total `unread_count` |
| POST | `/society/groups` | `name` (80), `member_ids` (at least one; must be your friends) — 20 per minute |
| GET | `/society/groups/{id}` | — one group |
| PATCH | `/society/groups/{id}` | `name` |
| DELETE | `/society/groups/{id}` | — owner only |
| POST | `/society/groups/{id}/members` | `user_ids` — your friends who aren’t in it yet |
| DELETE | `/society/groups/{id}/members/{userId}` | — leave (your own id) or, as owner, remove someone |
| GET | `/society/groups/{id}/messages` | — last 100 messages, oldest first; marks the group read |
| POST | `/society/groups/{id}/messages` | `body` (2000) — 60 per minute |

Each group has `name`, `is_owner`, `member_count`, `members` (owner first, each with `is_owner`), `last_message` and `unread_count`. Messages have `kind` (`text`, or `system` for joins, leaves and renames, worded as a sentence such as “You added Ana Cruz”), `body`, `author`, `mine` and `created_at`.

## Messaging (real-time 1-to-1)

Conversations between two friends, stored in `conversations`, `conversation_participants` and `messages`, and pushed live over WebSockets by [Laravel Reverb](https://reverb.laravel.com) (Pusher protocol). The sender is always the JWT user; anything the client sends as `sender_id` is ignored. Only participants can see a conversation (404 otherwise), and you can only start or write in a conversation with a friend (403).

All under `/api/v1`, Bearer auth required.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/conversations` | — conversations with at least one message, newest first: `other_participant`, `latest_message`, `latest_message_at`, `unread_count`; plus a total `unread_count` |
| POST | `/conversations` | `user_id` — open the conversation with a friend, creating it the first time (201, otherwise 200). 60 per minute |
| GET | `/conversations/{id}` | — one conversation |
| GET | `/conversations/{id}/messages` | `before` (message id), `limit` (default 50, max 100) — oldest to newest, with `has_more` and `next_before` for the page before |
| POST | `/conversations/{id}/messages` | `message` (1–2000, trimmed), `client_id` (optional, 64, echoed back) — 201 `{ data, live }`. 60 per minute |
| POST | `/conversations/{id}/read` | — marks the other person’s messages read; returns the `message_ids` it changed. 120 per minute |

A message is `{ id, conversation_id, sender_id, sender, message, message_type, is_read, read_at, created_at }`. `live` is `false` when Reverb couldn’t be reached: the message is saved either way and shows up for the other person when their connection comes back (the app refetches after reconnecting).

**Events** (broadcast only after the message is saved, and never back to the tab that caused them via `X-Socket-ID`):

| Event | Channels | Payload |
|-------|----------|---------|
| `MessageSent` | `private-conversation.{id}`, `private-user.{participantId}` | the message, plus `client_id` |
| `MessagesRead` | same | `conversation_id`, `reader_id`, `message_ids`, `read_at` |

`private-user.{id}` lets the app update conversation lists and unread badges anywhere. `presence-online.{id}` (members `{ id, name }`) shows who is online, and its `client-typing` whispers (`{ user_id, name, avatar_url, typing }`) carry the "is typing..." indicator. Group Society chats get the same whispers on `private-group.{groupId}`, which only group members can join (group messages themselves are still fetched over the API). Neither presence nor typing is stored in MySQL; whispers need `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=members` (the default).

**Channel auth:** `POST /api/v1/broadcasting/auth` (`socket_id`, `channel_name`) with the same `Authorization: Bearer` JWT, defined in `routes/channels.php`. Non-participants (or non-members, for groups) get 403, missing or invalid tokens 401. There is no session or cookie auth.

**Running Reverb.** Broadcast events implement `ShouldBroadcastNow`, so no queue worker is needed. A single Reverb server needs no Redis; Redis is only needed to run several Reverb servers behind a load balancer (`REVERB_SCALING_ENABLED`). Relevant `.env` keys:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...            # generated by php artisan install:broadcasting / reverb:install
REVERB_APP_KEY=...           # public; the frontend uses it as VITE_REVERB_APP_KEY
REVERB_APP_SECRET=...        # server only, never give it to the frontend
REVERB_HOST=127.0.0.1        # where Laravel reaches Reverb to publish events
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0   # what reverb:start listens on
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=*     # comma-separated hosts allowed to connect, e.g. app.example.com
```

In production run `php artisan reverb:start` as a long-lived process (Supervisor or systemd) behind an Nginx reverse proxy that serves `wss://` on 443. Then set `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` to how Laravel reaches it, and `REVERB_ALLOWED_ORIGINS` to the frontend host. After changing `.env`, run `php artisan config:clear` and restart Reverb (`php artisan reverb:restart`).

## Notifications

Bearer auth required. Built from what others did that involves you in the last 30 days — nothing is stored separately.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/notifications` | — newest first (40 max) plus `unread_count` |
| POST | `/notifications/read` | — marks everything up to now as seen |

Each item has `id`, `type`, `actor` (`id`, `name`, `avatar_url`), `others_count`, `subject`, `context`, `url` (in-app path to open), `created_at` and `unread` (newer than your last `/notifications/read`). Types:

| `type` | When | `subject` / `context` | `url` |
|--------|------|-----------------------|-------|
| `friend_request` | someone asks to be your friend (still pending) | — | `/society` |
| `friend_accepted` | someone accepts your request | — | `/people/{id}` |
| `post_like` | reactions on one of your posts, one item per post; `actor` is the latest, `others_count` the rest, `reaction` the latest one and `reactions` every kind used (most used first) | post snippet | `/feed?post={id}` |
| `post_comment` | a comment on your post | comment snippet | `/feed?post={id}` |
| `repost` | someone reposts your post | post snippet | `/feed?post={id}` |
| `join_request` | someone asks to join a club you own | club name | club page |
| `club_activity` | someone else plans an activity in a club you’re in | activity title / club name | `/activities` |

## Feed

All under `/api/v1`, Bearer auth required.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/posts` | `cursor` (from `next_cursor`) — newest first, 10 per page |
| GET | `/users/{id}/posts` | `cursor` — posts and reposts that person made (their profile’s Posts section), newest first, 10 per page; club posts only if you’re in that club |
| POST | `/posts` | multipart: `body` (max 3000), `image` (jpg/png/gif/webp, max 5 MB), `repost_of_id` (repost with thoughts) or `tournament_id` (share a tournament) — at least one required. Optional `club_id` posts it in a club or community you belong to (403 otherwise) |
| GET | `/posts/{id}` | — |
| DELETE | `/posts/{id}` | — (own posts only) |
| POST | `/posts/{id}/like` | `reaction` — `like` (default), `love`, `care`, `haha`, `wow`, `sad` or `angry`. One reaction per person; posting again switches it |
| DELETE | `/posts/{id}/like` | — take your reaction back |
| GET | `/posts/{id}/reactions` | — who reacted (`user`, `reaction`, `is_me`; newest first, 200 max), plus `reactions` and `total` |
| POST / DELETE | `/posts/{id}/repost` | — (plain repost / undo) |
| GET / POST | `/posts/{id}/comments` | `body` (max 1000) when posting |
| DELETE | `/comments/{id}` | — (comment author or post owner) |
| POST | `/comments/{id}/like` | `reaction` — same choices as posts, `like` by default. One reaction per person; posting again switches it. Returns `comment_id`, `my_reaction`, `likes_count`, `reactions` |
| DELETE | `/comments/{id}/like` | — take your reaction back |
| GET | `/comments/{id}/reactions` | — who reacted to the comment, same shape as `/posts/{id}/reactions` |

Each post carries `likes_count` (all reactions), `my_reaction` (yours or `null`; `liked` is `true` when set) and `reactions` — `[{ type, count }]`, most used first. Comments carry the same `likes_count`, `my_reaction` and `reactions`; comment reactions follow the post’s visibility (404 on club posts you can’t see) and are deleted with the comment.

Each post has a `club` (`id`, `type`, `name`, `color`, `avatar_url`) or `null` for public posts. Club posts are only visible to that club’s members (and their author): everyone else gets them filtered from the feed and a 404 on the post, like and comment routes. They can’t be reposted or quoted (422), and they’re deleted along with the club.

Sharing a tournament (`tournament_id`, text optional) needs a tournament you can see (404 otherwise). Public tournaments go to the feed; private club tournaments always go to their club’s wall (so only members see them, and you must be one); invite-only tournaments without a club can’t be shared (422). Each post has `tournament` (the tournament summary, for the card) or `null`, and `tournament_hidden` (`true` when a tournament was shared but the viewer can’t see it anymore, e.g. it turned private). Shares are deleted with their tournament. Tournament summaries have `share_to`: `feed`, `club` or `null` (where the viewer can share it).

## Shorts

A short is either a vertical video (up to 60 seconds and 40 MB) or a single photo (up to 10 MB). All under `/api/v1`, Bearer auth required.

| Method | Path | Body / query |
|--------|------|--------------|
| GET | `/shorts` | `feed` — `all` (default: everything you may see), `society` (your friends’ shorts), `clubs` (shorts in your clubs and communities) or `mine`; optional `user` (one person’s shorts), `club` (one club’s; 403 unless you’re a member), `limit` (1–20, default 8), `cursor`. Newest first |
| POST | `/shorts` | multipart: `kind` (`video`, the default, or `image`); for videos `video` (MP4, MOV or WebM, max 40 MB), `poster` (optional cover image, max 5 MB) and `duration` (seconds, max 60); for photos `image` (JPG, PNG, WebP or GIF, max 10 MB); plus `caption` (max 500), `audience` (`everyone`, `society` or `club`), `club_id` (required for `club`; you must be a member, 403 otherwise), `width`, `height` |
| GET | `/shorts/{id}` | — (404 when it’s not shared with you) |
| DELETE | `/shorts/{id}` | — (own shorts only; removes the video, cover or photo) |
| POST | `/shorts/{id}/view` | — counts you as a viewer (once per person; the author's own views don't count). Returns `short_id`, `views_count` |
| POST / DELETE | `/shorts/{id}/like` | `reaction` — same choices as posts. Returns `short_id`, `my_reaction`, `likes_count`, `reactions` |
| GET | `/shorts/{id}/reactions` | — who reacted, same shape as `/posts/{id}/reactions` |
| GET / POST | `/shorts/{id}/comments` | `body` (max 1000) when posting |
| DELETE | `/short-comments/{id}` | — (comment author or short owner) |
| POST / DELETE | `/short-comments/{id}/like` | `reaction` (optional, default `like`) — react to a comment, or take it back |
| GET | `/short-comments/{id}/reactions` | — who reacted to a comment, same shape as `/comments/{id}/reactions` |

Who sees a short depends on its `audience`: `everyone` is anyone on denuwe, `society` is the author’s accepted friends, `club` is the members of `club`. Authors always see their own. Anything you can’t see is left out of lists and gets a 404 on the short, reaction and comment routes. Each short has `kind`, `video_url`, `poster_url` and `image_url` (whichever apply, otherwise `null`), `duration` (seconds; `null` for photos), `width`, `height`, `audience`, `club`, `author`, `is_mine`, `views_count`, `likes_count`, `comments_count`, `my_reaction` and `reactions`. Each comment carries its own `likes_count`, `my_reaction` and `reactions`.

The browser measures the length and grabs the cover frame (the server has no video tooling), so `duration` is what the client reports. PHP’s `upload_max_filesize` / `post_max_size` must allow the full upload: raise `post_max_size` a little above 40M (e.g. 48M) so a 40 MB video plus its cover fits.

Photos (post images, avatars, banners) are stored on the `media` disk: DigitalOcean Spaces when `DO_SPACES_BUCKET` is set (files go under the `DO_SPACES_ROOT` folder, `denuwe` by default, since the bucket can be shared), otherwise the local `public` disk, which needs `php artisan storage:link`. Run `php artisan media:push` to copy images already in local storage up to Spaces.
