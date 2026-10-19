<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->boolean('to_issue')->default(true)->after('path'); // send it to the YouTrack issue when it is created/saved
            $table->string('youtrack_issue', 80)->nullable()->after('to_issue'); // key of the issue it was already uploaded to
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['to_issue', 'youtrack_issue']);
        });
    }
};
