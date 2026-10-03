<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // First-round pairs that play an opening match; the rest give their top slot a bye. Null = spread out.
            $table->json('opening_matches')->nullable()->after('draw_size');
            // Custom round titles counted back from the final (0 = final, 1 = semifinals …); null = the usual names.
            $table->json('round_names')->nullable()->after('opening_matches');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['opening_matches', 'round_names']);
        });
    }
};
