<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('type', 16)->default('club')->after('owner_id');
            $table->decimal('fee_amount', 10, 2)->nullable()->after('color');
            $table->string('fee_currency', 3)->default('PHP')->after('fee_amount');
            $table->string('fee_period', 16)->nullable()->after('fee_currency');
        });

        Schema::table('club_members', function (Blueprint $table) {
            $table->string('fee_status', 16)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('club_members', function (Blueprint $table) {
            $table->dropColumn('fee_status');
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['type', 'fee_amount', 'fee_currency', 'fee_period']);
        });
    }
};
