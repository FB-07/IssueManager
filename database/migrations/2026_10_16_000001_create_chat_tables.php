<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 64)->index(); // anonymous per-browser id (cookie)
            $table->string('title')->default('');
            $table->timestamps();
            $table->index(['owner', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // user | assistant
            $table->string('kind', 20)->default('text'); // text | creation-result
            $table->longText('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // image | file
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('issue_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('summary')->nullable();
            $table->longText('description')->nullable();
            $table->string('project')->nullable();
            $table->string('type', 60)->nullable();
            $table->string('priority', 60)->nullable();
            $table->string('assignee')->nullable();
            $table->json('labels')->nullable();
            $table->string('sprint')->nullable();
            $table->string('subsystem')->nullable();
            $table->json('custom_fields')->nullable();
            $table->string('status', 12)->default('draft'); // draft | created | error
            $table->string('issue_key')->nullable();
            $table->string('issue_url')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_drafts');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
