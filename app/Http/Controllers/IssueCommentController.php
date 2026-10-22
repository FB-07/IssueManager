<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;

/** Posts a comment the user asked for in the chat on an existing YouTrack issue, or rewrites one the app posted, and records it in the conversation. */
class IssueCommentController extends Controller
{
    use ScopesToUser;

    public function __invoke(Request $request, int $conversation, YouTrackClient $yt)
    {
        $c = $this->conversation($request, $conversation);

        $data = $request->validate([
            'issue_id' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.]+(-[A-Za-z0-9_.]+)*-\d+$/'],
            'text' => ['required', 'string', 'max:10000'],
            'comment_id' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]+-[0-9]+$/'],
            'in_place' => ['nullable', 'boolean'],
        ]);
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        $text = trim($data['text']);
        if ($text === '') {
            return response()->json(['message' => __('ui.errors.nothing_to_change')], 422);
        }

        if (filled($data['comment_id'] ?? null)) {
            return $this->edit($c, $yt, $data['issue_id'], $data['comment_id'], $text, (bool) ($data['in_place'] ?? false));
        }

        try {
            $comment = $yt->addComment($data['issue_id'], $text);
            $issue = $yt->getIssue($data['issue_id']);
        } catch (TrackerException $e) {
            return $e->response();
        }

        $message = $c->messages()->create([
            'role' => 'assistant',
            'kind' => 'creation-result',
            'body' => null,
            'meta' => [
                'key' => $issue['id'], 'url' => $issue['url'], 'updated' => true, 'direct' => true, 'commented' => true,
                'summary' => $issue['summary'], 'comment' => $text, 'comment_id' => $comment['id'],
            ],
        ]);
        $c->touch();

        return ['message' => Presenter::message($message->load('attachments')), 'conversation' => Presenter::conversation($c->fresh())];
    }

    /** The user's own comments can be rewritten (never someone else's); one the app posted here also has its card updated in place. */
    private function edit($c, YouTrackClient $yt, string $issueId, string $commentId, string $text, bool $inPlace)
    {
        $message = $c->messages()->where('kind', 'creation-result')->get()->first(
            fn ($m) => ($m->meta['comment_id'] ?? null) === $commentId && strcasecmp((string) ($m->meta['key'] ?? ''), $issueId) === 0
        );

        try {
            if (! $message) {
                $mine = $yt->me()['login'] ?? null;
                $comment = collect($yt->comments($issueId))->firstWhere('id', $commentId);
                if (! $comment) {
                    return response()->json(['message' => __('ui.errors.comment_not_found')], 404);
                }
                if (! $mine || $comment['author_login'] !== $mine) {
                    return response()->json(['message' => __('ui.errors.comment_not_yours')], 403);
                }
            }
            $yt->editComment($issueId, $commentId, $text);
            $issue = $message && $inPlace ? null : $yt->getIssue($issueId);
        } catch (TrackerException $e) {
            return $e->response();
        }

        // the "edit" button rewrites its own card; an edit asked for in the chat gets a card of its own (and the old one follows the new text)
        $original = null;
        if ($message) {
            $message->update(['meta' => [...$message->meta, 'comment' => $text, 'edited' => true]]);
            $original = Presenter::message($message->fresh()->load('attachments'));
        }
        if (! $message || ! $inPlace) {
            $message = $c->messages()->create([
                'role' => 'assistant', 'kind' => 'creation-result', 'body' => null,
                'meta' => ['key' => $issue['id'], 'url' => $issue['url'], 'updated' => true, 'direct' => true, 'commented' => true, 'summary' => $issue['summary'], 'comment' => $text, 'comment_id' => $commentId, 'edited' => true],
            ]);
            $c->touch();

            return ['message' => Presenter::message($message->load('attachments')), 'original' => $original, 'conversation' => Presenter::conversation($c->fresh())];
        }
        $c->touch();

        return ['message' => $original, 'conversation' => Presenter::conversation($c->fresh())];
    }
}
