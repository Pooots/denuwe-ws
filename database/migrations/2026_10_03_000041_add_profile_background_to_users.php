<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile page background: a template design, or the user's own photo shown with one of the photo looks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = the default page colour, a template key, or "photo".
            $table->string('profile_background', 32)->nullable()->after('banner_path');
            $table->string('profile_background_path')->nullable()->after('profile_background');
            $table->string('profile_background_effect', 16)->nullable()->after('profile_background_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_background', 'profile_background_path', 'profile_background_effect']);
        });
    }
};
