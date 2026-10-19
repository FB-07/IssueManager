<?php

namespace App\Services;

use App\Exceptions\TrackerException;
use App\Models\Attachment;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Sends conversation attachments to a YouTrack issue; one failing file never blocks the others (or the issue). */
class AttachmentUploader
{
    /**
     * @param  Collection<int,Attachment>  $attachments
     * @return array{sent:list<string>,failed:list<string>}
     */
    public function upload(YouTrackClient $yt, string $issueKey, Collection $attachments): array
    {
        $sent = [];
        $failed = [];

        foreach ($attachments as $a) {
            try {
                if (! Storage::disk('local')->exists($a->path)) {
                    throw new TrackerException('missing file');
                }
                $yt->attach($issueKey, $a->name, (string) $a->mime, Storage::disk('local')->get($a->path));
                $a->update(['youtrack_issue' => $issueKey]);
                $sent[] = $a->name;
            } catch (TrackerException) {
                $failed[] = $a->name;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
