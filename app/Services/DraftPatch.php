<?php

namespace App\Services;

use App\Exceptions\TrackerException;
use App\Services\YouTrack\YouTrackClient;

/** Never trust the model: keeps only known fields and values that exist in the chosen project. */
class DraftPatch
{
    private const OPTIONS = ['type' => 'types', 'priority' => 'priorities', 'subsystem' => 'subsystems', 'sprint' => 'sprints'];

    /**
     * @param  array<string,mixed>|null  $raw
     * @return array<string,mixed>|null patch (custom_fields merged by the caller) or null when nothing usable
     *
     * @throws TrackerException
     */
    public static function sanitize(?array $raw, ?string $currentProject, YouTrackClient $yt, bool $withState = false): ?array
    {
        if (! $raw) {
            return null;
        }

        $projects = array_column($yt->projects(), 'key');
        $project = is_string($raw['project'] ?? null) && in_array($raw['project'], $projects, true) ? $raw['project'] : $currentProject;
        $meta = $project ? $yt->meta($project) : null;

        $patch = [];
        foreach (['summary' => 255, 'description' => 50000] as $f => $max) {
            if (is_string($raw[$f] ?? null) && trim($raw[$f]) !== '') {
                $patch[$f] = mb_substr($raw[$f], 0, $max);
            }
        }
        if (is_string($raw['project'] ?? null) && in_array($raw['project'], $projects, true)) {
            $patch['project'] = $raw['project'];
        }
        if ($meta) {
            foreach (self::OPTIONS as $f => $key) {
                if (is_string($raw[$f] ?? null) && in_array($raw[$f], $meta[$key], true)) {
                    $patch[$f] = $raw[$f];
                }
            }
            if ($withState && is_string($raw['state'] ?? null) && in_array($raw['state'], $meta['states'] ?? [], true)) {
                $patch['state'] = $raw['state'];
            }
            if (is_string($raw['assignee'] ?? null) && in_array($raw['assignee'], array_column($meta['assignees'], 'value'), true)) {
                $patch['assignee'] = $raw['assignee'];
            }
            if (is_array($raw['custom_fields'] ?? null)) {
                $cf = [];
                foreach ($meta['custom'] as $def) {
                    $v = $raw['custom_fields'][$def['key']] ?? null;
                    if (is_string($v) && in_array($v, $def['values'], true)) {
                        $cf[$def['key']] = $v;
                    }
                }
                if ($cf) {
                    $patch['custom_fields'] = $cf;
                }
            }
        }
        if (is_array($raw['labels'] ?? null)) {
            $patch['labels'] = array_slice(array_values(array_unique(array_filter(
                array_map(fn ($l) => is_string($l) ? mb_substr(trim($l), 0, 60) : '', $raw['labels'])
            ))), 0, 20);
        }

        return $patch ?: null;
    }
}
