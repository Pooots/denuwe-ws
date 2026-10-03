<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournaments outgrow clubs: anyone can host one (optionally for a club), for players or teams, with a
 * single-elimination bracket or a round robin. Replaces the club-only sign-up tables, which held no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('club_tournament_participants');
        Schema::dropIfExists('club_tournaments');

        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('game', 80)->nullable();
            $table->string('format', 20)->default('individual');
            $table->unsignedTinyInteger('team_size')->nullable();
            $table->string('bracket', 30)->default('single_elimination');
            $table->dateTime('starts_at');
            $table->string('location', 120)->nullable();
            $table->string('prize', 120)->nullable();
            $table->unsignedSmallInteger('max_entries')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('registration');
            $table->unsignedBigInteger('winner_entry_id')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('tournament_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->foreignId('captain_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('seed')->nullable();
            $table->timestamps();
        });

        Schema::create('tournament_entry_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('tournament_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('tournament_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('position');
            $table->foreignId('entry1_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('entry2_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->unsignedInteger('score1')->nullable();
            $table->unsignedInteger('score2')->nullable();
            $table->foreignId('winner_entry_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'round', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_invites');
        Schema::dropIfExists('tournament_entry_members');
        Schema::dropIfExists('tournament_entries');
        Schema::dropIfExists('tournaments');

        Schema::create('club_tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('game', 80)->nullable();
            $table->dateTime('starts_at');
            $table->string('location', 120)->nullable();
            $table->string('prize', 120)->nullable();
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'starts_at']);
        });

        Schema::create('club_tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('club_tournaments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });
    }
};
