<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedSmallInteger('draw_size')->nullable()->after('bracket');
        });
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot')->nullable()->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_entries', function (Blueprint $table) {
            $table->dropColumn('slot');
        });
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('draw_size');
        });
    }
};
