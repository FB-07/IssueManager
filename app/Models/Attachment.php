<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = ['conversation_id', 'message_id', 'kind', 'name', 'mime', 'size', 'path', 'to_issue', 'youtrack_issue'];

    protected function casts(): array
    {
        return ['to_issue' => 'boolean'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
