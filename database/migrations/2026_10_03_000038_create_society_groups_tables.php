<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('society_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // Passed to the longest-standing member when the owner leaves.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('society_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamps();

            $table->unique(['society_group_id', 'user_id']);
        });

        Schema::create('society_group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('society_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // `text` from a member, or `system` for joins, leaves and renames (body is the action, e.g. "left the group").
            $table->string('kind', 10)->default('text');
            $table->text('body');
            $table->timestamps();

            $table->index(['society_group_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('society_group_messages');
        Schema::dropIfExists('society_group_members');
        Schema::dropIfExists('society_groups');
    }
};
