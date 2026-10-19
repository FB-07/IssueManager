<?php

namespace App\Services;

/** Clickable answers the model offers under a question. Never trust them: trimmed, capped and typed here. */
class QuickReplies
{
    public const KINDS = ['text', 'person', 'project'];

    /** Buttons the app itself adds (never accepted from the model): kind => allowed values. */
    public const ACTIONS = ['action' => ['choose_assignee']];

    /**
     * @return list<array{label:string,text:string,kind:string,value:?string}>
     */
    public static function sanitize(mixed $raw, bool $allowActions = false): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $r) {
            if (! is_array($r) || ! is_string($r['label'] ?? null) || trim($r['label']) === '') {
                continue;
            }
            $label = mb_substr(trim($r['label']), 0, 40);
            $text = is_string($r['text'] ?? null) && trim($r['text']) !== '' ? mb_substr(trim($r['text']), 0, 200) : $label;
            $kind = in_array($r['kind'] ?? null, self::KINDS, true) ? $r['kind'] : 'text';
            $value = is_string($r['value'] ?? null) ? mb_substr(trim($r['value']), 0, 80) : null;
            if ($allowActions && ($r['kind'] ?? null) === 'action' && in_array($value, self::ACTIONS['action'], true)) {
                $kind = 'action';
            }

            if (! isset($out[mb_strtolower($label)])) { // no duplicated buttons
                $out[mb_strtolower($label)] = compact('label', 'text', 'kind', 'value');
            }
            if (count($out) === 4) {
                break;
            }
        }

        return array_values($out);
    }
}
