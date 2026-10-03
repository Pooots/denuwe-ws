<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('birthday')->nullable()->after('last_name');
            $table->string('gender', 32)->nullable()->after('birthday');
            // Users can sign up with a mobile number instead of an email.
            $table->string('email')->nullable()->change();
            $table->string('phone', 32)->nullable()->change();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['first_name', 'last_name', 'birthday', 'gender']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
