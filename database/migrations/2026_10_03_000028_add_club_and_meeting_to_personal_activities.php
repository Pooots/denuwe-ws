<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_activities', function (Blueprint $table) {
            $table->foreignId('club_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->boolean('is_meeting')->default(false)->after('location');
            $table->string('meeting_url', 500)->nullable()->after('is_meeting');
        });
    }

    public function down(): void
    {
        Schema::table('personal_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
            $table->dropColumn(['is_meeting', 'meeting_url']);
        });
    }
};
