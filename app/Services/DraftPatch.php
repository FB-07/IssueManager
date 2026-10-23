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
                    $v = is_int($v) || is_float($v) ? (string) $v : $v;
                    if (is_string($v) && self::validCustom($def, $v)) {
                        $cf[$def['key']] = mb_substr(trim($v), 0, 255);
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

    /** Whether `$v` is acceptable for a custom field of the project: one of its values, or a well formed number / date / text. */
    public static function validCustom(array $def, string $v): bool
    {
        $v = trim($v);

        return match ($def['kind'] ?? 'enum') {
            'enum' => in_array($v, $def['values'] ?? [], true),
            'number' => is_numeric($v),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false,
            default => $v !== '', // string, text, period: free text (the tracker rejects what it does not understand)
        };
    }
}
