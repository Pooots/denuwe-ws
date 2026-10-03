<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tournament can have several named brackets (e.g. Men's and Women's), each with its own size, draw, round
 * names and champion. The draw that used to live on the tournament becomes its "Main bracket".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('size');
            $table->unsignedTinyInteger('position')->default(0);
            $table->json('opening_matches')->nullable();
            $table->json('round_names')->nullable();
            $table->foreignId('winner_entry_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->foreignId('bracket_id')->nullable()->after('tournament_id')->constrained('tournament_brackets')->nullOnDelete();
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->foreignId('bracket_id')->nullable()->after('tournament_id')->constrained('tournament_brackets')->cascadeOnDelete();
        });
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unique(['tournament_id', 'bracket_id', 'side', 'round', 'position'], 'tournament_matches_bracket_spot_unique');
            $table->dropUnique(['tournament_id', 'side', 'round', 'position']);
        });

        $now = now();
        DB::table('tournaments')->whereNotNull('draw_size')->where('bracket', '!=', 'round_robin')->orderBy('id')
            ->get()
            ->each(function (object $tournament) use ($now) {
                $bracketId = DB::table('tournament_brackets')->insertGetId([
                    'tournament_id' => $tournament->id,
                    'name' => 'Main bracket',
                    'size' => $tournament->draw_size,
                    'position' => 0,
                    'opening_matches' => $tournament->opening_matches,
                    'round_names' => $tournament->round_names,
                    'winner_entry_id' => $tournament->status === 'completed' ? $tournament->winner_entry_id : null,
                    'completed_at' => $tournament->status === 'completed' ? $tournament->completed_at : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('tournament_entries')->where('tournament_id', $tournament->id)
                    ->whereNotNull('slot')->where('slot', '<', $tournament->draw_size)
                    ->update(['bracket_id' => $bracketId]);
                DB::table('tournament_entries')->where('tournament_id', $tournament->id)->whereNull('bracket_id')
                    ->update(['slot' => null]);
                DB::table('tournament_matches')->where('tournament_id', $tournament->id)->update(['bracket_id' => $bracketId]);
                DB::table('tournaments')->where('id', $tournament->id)->update(['max_entries' => $tournament->draw_size]);
            });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['draw_size', 'opening_matches', 'round_names']);
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedSmallInteger('draw_size')->nullable()->after('bracket');
            $table->json('opening_matches')->nullable()->after('draw_size');
            $table->json('round_names')->nullable()->after('opening_matches');
        });

        // Only the first bracket fits back on the tournament; entries and matches of the others are dropped from it.
        DB::table('tournament_brackets')->orderBy('position')->orderBy('id')->get()->groupBy('tournament_id')
            ->each(function ($brackets, $tournamentId) {
                $first = $brackets->first();
                DB::table('tournaments')->where('id', $tournamentId)->update([
                    'draw_size' => $first->size,
                    'opening_matches' => $first->opening_matches,
                    'round_names' => $first->round_names,
                ]);
                DB::table('tournament_entries')->where('tournament_id', $tournamentId)
                    ->where(fn ($q) => $q->whereNull('bracket_id')->orWhere('bracket_id', '!=', $first->id))
                    ->update(['slot' => null]);
                DB::table('tournament_matches')->where('tournament_id', $tournamentId)->where('bracket_id', '!=', $first->id)->delete();
            });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unique(['tournament_id', 'side', 'round', 'position']);
            $table->dropUnique('tournament_matches_bracket_spot_unique');
            $table->dropConstrainedForeignId('bracket_id');
        });
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bracket_id');
        });
        Schema::dropIfExists('tournament_brackets');
    }
};
