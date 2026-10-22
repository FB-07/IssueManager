<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToUser;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes back the last exchange (the user's message and Claude's answer) so it can be edited or answered again. Only when
 * nothing outside the chat depends on it: an answer that created, changed or commented on something in YouTrack stays.
 */
class MessageRedoController extends Controller
{
    use ScopesToUser;

    public function __invoke(Request $request, int $conversation)
    {
        $c = $this->conversation($request, $conversation);

        $last = $c->messages()->with('attachments')->reorder('id', 'desc')->limit(2)->get();
        $assistant = $last[0] ?? null;
        $user = $last[1] ?? null;
        $meta = $assistant?->meta ?? [];
        $draft = $c->draft;

        $blocked = ! $assistant || ! $user
            || $assistant->role !== 'assistant' || $assistant->kind !== 'text' || $user->role !== 'user'
            || ! empty($meta['direct'])
            || collect($meta['batch']['items'] ?? [])->contains(fn ($i) => ($i['status'] ?? 'pending') !== 'pending')
            || ($draft && $draft->status !== 'draft' && array_key_exists('draft_before', $meta))
            || $user->attachments->contains(fn ($a) => filled($a->youtrack_issue));
        if ($blocked) {
            return response()->json(['message' => __('ui.errors.cannot_redo')], 409);
        }

        $removed = Presenter::message($user);

        DB::transaction(function () use ($c, $user, $assistant, $meta, $draft) {
            // the draft goes back to what it was before this answer changed it
            if (array_key_exists('draft_before', $meta)) {
                $before = $meta['draft_before'];
                if (isset($before['__none'])) {
                    $draft?->delete();
                } elseif ($draft) {
                    $draft->forceFill($before)->save();
                } else {
                    $c->draft()->create($before);
                }
            }
            foreach ($user->attachments as $a) {
                Storage::disk('local')->delete($a->path);
                $a->delete();
            }
            $assistant->delete();
            $user->delete();
            $c->touch();
        });

        return [
            'removed' => $removed,
            'draft' => Presenter::draft($c->draft()->first()),
            'conversation' => Presenter::conversation($c->fresh()),
        ];
    }
}
