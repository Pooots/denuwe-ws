<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('group_stage', 8)->nullable()->after('bracket');
            $table->unsignedTinyInteger('group_count')->default(2)->after('group_stage');
            $table->string('stage', 10)->nullable()->after('status');
        });
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('group_number')->nullable()->after('bracket_id');
            $table->boolean('advanced')->default(false)->after('group_number');
        });
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('group_number')->nullable()->after('bracket_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('group_number');
        });
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->dropColumn(['group_number', 'advanced']);
        });
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['group_stage', 'group_count', 'stage']);
        });
    }
};
