<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Double elimination: a match is in the winners bracket, the losers bracket or the grand final. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->string('side', 8)->default('winners')->after('tournament_id');
        });
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unique(['tournament_id', 'side', 'round', 'position']);
            $table->dropUnique(['tournament_id', 'round', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unique(['tournament_id', 'round', 'position']);
            $table->dropUnique(['tournament_id', 'side', 'round', 'position']);
            $table->dropColumn('side');
        });
    }
};
