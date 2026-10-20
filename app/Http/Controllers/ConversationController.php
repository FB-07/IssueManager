<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversationController extends Controller
{
    use ScopesToUser;

    public function store(Request $request)
    {
        $c = $request->user()->conversations()->create(['title' => '']);

        return response()->json(Presenter::conversation($c), 201);
    }

    public function show(Request $request, int $conversation)
    {
        $c = $this->conversation($request, $conversation)->load('messages.attachments', 'draft');

        return [
            'conversation' => Presenter::conversation($c),
            'messages' => $c->messages->map(Presenter::message(...))->all(),
            'draft' => Presenter::draft($c->draft),
        ];
    }

    public function update(Request $request, int $conversation)
    {
        $c = $this->conversation($request, $conversation);
        $c->update($request->validate(['title' => ['required', 'string', 'max:120']]));

        return Presenter::conversation($c);
    }

    /**
     * Links the chat to a YouTrack issue (or clears the link with `issue_key: null`). The issue must exist and be visible to the
     * user; from then on "change it" or "close it" in this chat refers to it.
     */
    public function context(Request $request, int $conversation, YouTrackClient $yt)
    {
        $c = $this->conversation($request, $conversation);
        $data = $request->validate(['issue_key' => ['present', 'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.]+(-[A-Za-z0-9_.]+)*-\d+$/']]);

        if ($data['issue_key'] === null) {
            $c->update(['issue_key' => null, 'issue_summary' => null]);

            return ['conversation' => Presenter::conversation($c), 'issue' => null];
        }
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        try {
            $issue = $yt->getIssue($data['issue_key']);
        } catch (TrackerException $e) {
            return $e->response();
        }
        $c->update(['issue_key' => $issue['id'], 'issue_summary' => mb_substr($issue['summary'], 0, 255)]);

        return ['conversation' => Presenter::conversation($c), 'issue' => $issue];
    }

    /** Deletes several of the user's conversations at once; ids that are not theirs are silently ignored. */
    public function destroyMany(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);

        $conversations = $request->user()->conversations()->whereIn('id', $data['ids'])->get();
        foreach ($conversations as $c) {
            Storage::disk('local')->deleteDirectory("attachments/{$c->id}");
            $c->delete();
        }

        return ['deleted' => $conversations->pluck('id')->all()];
    }

    public function destroy(Request $request, int $conversation)
    {
        $c = $this->conversation($request, $conversation);
        Storage::disk('local')->deleteDirectory("attachments/{$c->id}");
        $c->delete();

        return response()->noContent();
    }
}
