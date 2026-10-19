<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('hub_id')->nullable()->unique()->after('email');
            $table->string('avatar_url')->nullable()->after('hub_id');
            $table->string('theme', 10)->default('dark')->after('avatar_url');
            $table->string('locale', 5)->default('pt')->after('theme');
            $table->string('password')->nullable()->change(); // login is done through the YouTrack Hub
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('owner', 64)->nullable()->change(); // legacy anonymous cookie, only used to claim old history at first login
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['hub_id', 'avatar_url', 'theme', 'locale']);
        });
    }
};
