<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Conversation;
use Illuminate\Http\Request;

/** Every chat resource belongs to the logged-in user: other users' ids are plain 404s. */
trait ScopesToUser
{
    protected function conversation(Request $request, int|string $id): Conversation
    {
        return $request->user()->conversations()->findOrFail($id);
    }
}
