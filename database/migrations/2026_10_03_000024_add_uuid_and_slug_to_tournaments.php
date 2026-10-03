<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('slug', 120)->nullable()->after('name');
        });

        $taken = [];
        DB::table('tournaments')->orderBy('id')->eachById(function ($tournament) use (&$taken) {
            $base = trim(Str::limit(Str::slug($tournament->name), 90, ''), '-') ?: 'tournament';
            if (ctype_digit($base) || Str::isUuid($base)) {
                $base = 'tournament-'.$base;
            }
            $slug = $base;
            for ($n = 2; isset($taken[$slug]); $n++) {
                $slug = $base.'-'.$n;
            }
            $taken[$slug] = true;

            DB::table('tournaments')->where('id', $tournament->id)->update([
                'uuid' => (string) Str::uuid7(),
                'slug' => $slug,
            ]);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->string('slug', 120)->nullable(false)->change();
            $table->unique('uuid');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['uuid', 'slug']);
        });
    }
};
