<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Set only when the audience is a club or community.
            $table->foreignId('club_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('audience', 10)->default('everyone');
            $table->string('caption', 500)->nullable();
            $table->string('video_path');
            $table->string('poster_path')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();

            $table->index(['audience', 'id']);
        });

        Schema::create('short_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 10)->default('like');
            $table->timestamps();

            $table->unique(['short_id', 'user_id']);
        });

        Schema::create('short_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_comments');
        Schema::dropIfExists('short_likes');
        Schema::dropIfExists('shorts');
    }
};
