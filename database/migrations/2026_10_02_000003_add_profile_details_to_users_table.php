<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('gender');
            $table->string('banner_path')->nullable()->after('avatar_path');
            $table->string('headline', 160)->nullable()->after('banner_path');
            $table->string('pronouns', 32)->nullable()->after('headline');
            $table->string('location', 120)->nullable()->after('pronouns');
            $table->text('bio')->nullable()->after('location');
            $table->string('website')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'banner_path', 'headline', 'pronouns', 'location', 'bio', 'website']);
        });
    }
};
