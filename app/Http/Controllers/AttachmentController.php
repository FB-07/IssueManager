<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToUser;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    use ScopesToUser;

    public function show(Request $request, Attachment $attachment)
    {
        abort_unless($attachment->conversation->user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $inline = $attachment->kind === 'image' && in_array($attachment->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
