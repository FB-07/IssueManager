<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToUser;
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
