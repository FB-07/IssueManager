<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The context issue is not a property of the chat any more: it is the issue in focus while talking, chosen (and changed) freely, and
 * each user message records the one it was sent with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['issue_key', 'issue_summary']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('issue_key', 80)->nullable()->after('title');
            $table->string('issue_summary')->nullable()->after('issue_key');
        });
    }
};
