<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\IssueDraft;
use App\Models\Message;

/** JSON shapes shared by the chat API endpoints. */
class Presenter
{
    public static function conversation(Conversation $c): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'updated_at' => $c->updated_at?->toIso8601String()];
    }

    public static function message(Message $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'kind' => $m->kind,
            'body' => $m->body,
            'meta' => (object) ($m->meta ?? []),
            'created_at' => $m->created_at?->toIso8601String(),
            'attachments' => $m->attachments->map(fn ($a) => [
                'id' => $a->id, 'kind' => $a->kind, 'name' => $a->name, 'size' => $a->size, 'mime' => $a->mime,
                'url' => route('attachments.show', $a),
                'to_issue' => $a->to_issue,
                'uploaded_to' => $a->youtrack_issue,
            ])->values()->all(),
        ];
    }

    public static function draft(?IssueDraft $d): ?array
    {
        if (! $d) {
            return null;
        }

        return [
            'id' => $d->id,
            'summary' => $d->summary, 'description' => $d->description, 'project' => $d->project,
            'type' => $d->type, 'priority' => $d->priority, 'assignee' => $d->assignee,
            'labels' => $d->labels ?? [], 'sprint' => $d->sprint, 'subsystem' => $d->subsystem,
            'custom_fields' => (object) ($d->custom_fields ?? []),
            'suggestions' => (object) [],
            'status' => $d->status, 'issue_key' => $d->issue_key, 'issue_url' => $d->issue_url,
            'is_demo' => false, 'error_message' => $d->error_message, 'revision' => $d->revision,
            'missing' => $d->missingFields(),
        ];
    }
}
