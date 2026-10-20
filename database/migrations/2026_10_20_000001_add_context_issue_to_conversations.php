<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('issue_key', 80)->nullable()->after('title'); // the YouTrack issue this chat is about (its context)
            $table->string('issue_summary')->nullable()->after('issue_key');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['issue_key', 'issue_summary']);
        });
    }
};
