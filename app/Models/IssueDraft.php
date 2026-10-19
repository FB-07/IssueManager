<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueDraft extends Model
{
    public const REQUIRED = ['summary', 'description', 'project', 'type', 'priority'];

    public const EDITABLE = ['summary', 'description', 'project', 'type', 'priority', 'assignee', 'sprint', 'subsystem'];

    protected $fillable = [
        'conversation_id', 'summary', 'description', 'project', 'type', 'priority', 'assignee', 'labels',
        'sprint', 'subsystem', 'custom_fields', 'status', 'issue_key', 'issue_url', 'error_message', 'revision',
    ];

    protected function casts(): array
    {
        return ['labels' => 'array', 'custom_fields' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return list<string> */
    public function missingFields(): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($f) => blank($this->{$f})));
    }
}
