<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_positions', function (Blueprint $table) {
            $table->boolean('can_organize')->default(false)->after('sort_order');
        });

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

    public function down(): void
    {
        Schema::dropIfExists('club_tournament_participants');
        Schema::dropIfExists('club_tournaments');
        Schema::table('club_positions', function (Blueprint $table) {
            $table->dropColumn('can_organize');
        });
    }
};
