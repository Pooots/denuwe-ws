<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_entries', function (Blueprint $table) {
            // Elimination stage: the organizer's slot in the match map before the start (null = drawn at random).
            $table->unsignedSmallInteger('group_slot')->nullable()->after('group_number');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->dropColumn('group_slot');
        });
    }
};
