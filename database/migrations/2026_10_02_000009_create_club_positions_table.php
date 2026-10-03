<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['club_id', 'name']);
        });

        Schema::table('club_members', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('role')->constrained('club_positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('club_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::dropIfExists('club_positions');
    }
};
