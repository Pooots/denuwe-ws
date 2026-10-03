<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('mood', 16)->nullable();
            $table->string('title', 120)->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'entry_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_entries');
    }
};
