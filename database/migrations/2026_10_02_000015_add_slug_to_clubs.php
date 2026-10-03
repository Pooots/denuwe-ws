<?php

use App\Models\Club;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->unique()->after('name');
        });

        foreach (DB::table('clubs')->orderBy('id')->get(['id', 'name']) as $club) {
            DB::table('clubs')->where('id', $club->id)->update(['slug' => Club::uniqueSlug($club->name, $club->id)]);
        }
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
