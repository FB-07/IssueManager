<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Numbers for the analysis page: created / resolved per week, month or year, the open backlog, who has what open, and how the
 * open issues are spread (state, priority, type, project, age). Works on the light issue rows of YouTrackClient::analyticsIssues().
 */
class Analytics
{
    public const TZ = 'Europe/Lisbon';

    /** How many periods are shown for each granularity. */
    private const PERIODS = ['week' => 12, 'month' => 12, 'year' => 5];

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    public static function build(array $issues, string $granularity, ?CarbonImmutable $now = null): array
    {
        $granularity = isset(self::PERIODS[$granularity]) ? $granularity : 'week';
        $now ??= CarbonImmutable::now(self::TZ);
        $ms = fn (CarbonImmutable $d) => $d->getTimestamp() * 1000;

        // the periods, oldest first; the last one is the current (still running) period
        $buckets = [];
        $current = match ($granularity) {
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            'year' => $now->startOfYear(),
        };
        for ($i = self::PERIODS[$granularity] - 1; $i >= 0; $i--) {
            $start = match ($granularity) {
                'week' => $current->subWeeks($i),
                'month' => $current->subMonthsNoOverflow($i),
                'year' => $current->subYears($i),
            };
            $end = match ($granularity) {
                'week' => $start->addWeek(),
                'month' => $start->addMonthNoOverflow(),
                'year' => $start->addYear(),
            };
            $buckets[] = ['start' => $start, 'end' => $end, 'from' => $ms($start), 'to' => $ms($end)];
        }
        $rangeFrom = $buckets[0]['from'];
        $nowMs = $ms($now);

        $series = [];
        foreach ($buckets as $b) {
            $created = 0;
            $resolved = 0;
            $open = 0;
            $days = [];
            foreach ($issues as $i) {
                if ($i['created'] >= $b['from'] && $i['created'] < $b['to']) {
                    $created++;
                }
                if ($i['resolved'] !== null && $i['resolved'] >= $b['from'] && $i['resolved'] < $b['to']) {
                    $resolved++;
                    $days[] = max(0, ($i['resolved'] - $i['created']) / 86400000);
                }
                if ($i['created'] < $b['to'] && ($i['resolved'] === null || $i['resolved'] >= $b['to'])) {
                    $open++;
                }
            }
            $series[] = [
                'start' => $b['start']->toDateString(), 'end' => $b['end']->subDay()->toDateString(),
                'created' => $created, 'resolved' => $resolved, 'open' => $open,
                'avg_days' => $days ? round(array_sum($days) / count($days), 1) : null,
            ];
        }

        $openIssues = array_values(array_filter($issues, fn ($i) => $i['resolved'] === null));
        $resolvedInRange = array_values(array_filter($issues, fn ($i) => $i['resolved'] !== null && $i['resolved'] >= $rangeFrom));
        $durations = array_map(fn ($i) => max(0, ($i['resolved'] - $i['created']) / 86400000), $resolvedInRange);
        sort($durations);
        $createdInRange = array_sum(array_column($series, 'created'));
        $resolvedCount = array_sum(array_column($series, 'resolved'));
        $age = fn ($i) => max(0, ($nowMs - $i['created']) / 86400000);

        // who has what open (an issue with several assignees counts for each; none = null)
        $people = [];
        foreach ($issues as $i) {
            $names = $i['assignees'] ?: [null];
            foreach ($names as $n) {
                $key = $n ?? '';
                $people[$key] ??= ['name' => $n, 'open' => 0, 'resolved' => 0, 'ages' => []];
                if ($i['resolved'] === null) {
                    $people[$key]['open']++;
                    $people[$key]['ages'][] = $age($i);
                } elseif ($i['resolved'] >= $rangeFrom) {
                    $people[$key]['resolved']++;
                }
            }
        }
        $assignees = collect($people)->map(fn ($p) => [
            'name' => $p['name'], 'open' => $p['open'], 'resolved' => $p['resolved'],
            'avg_age' => $p['ages'] ? round(array_sum($p['ages']) / count($p['ages'])) : null,
            'oldest' => $p['ages'] ? (int) round(max($p['ages'])) : null,
        ])->filter(fn ($p) => $p['open'] > 0 || $p['resolved'] > 0)
            ->sortBy([['open', 'desc'], ['resolved', 'desc']])->values()->take(60)->all();

        $count = fn (array $rows, string $field, int $limit = 12) => collect($rows)->groupBy(fn ($i) => $i[$field] ?? '')
            ->map(fn ($g, $k) => ['name' => $k === '' ? null : $k, 'count' => $g->count()])->sortByDesc('count')->values()->take($limit)->all();

        $aging = [['max' => 7], ['max' => 30], ['max' => 90], ['max' => 180], ['max' => null]];
        foreach ($aging as &$a) {
            $a['count'] = 0;
        }
        unset($a);
        foreach ($openIssues as $i) {
            $d = $age($i);
            foreach ($aging as &$a) {
                if ($a['max'] === null || $d <= $a['max']) {
                    $a['count']++;
                    break;
                }
            }
            unset($a);
        }

        $states = collect($issues)->groupBy(fn ($i) => $i['state'] ?? '')->map(function ($g, $k) {
            return ['name' => $k === '' ? null : $k, 'count' => $g->count(), 'resolved' => $g->contains(fn ($i) => $i['resolved'] !== null) && $g->every(fn ($i) => $i['resolved'] !== null)];
        })->sortByDesc('count')->values()->take(14)->all();

        $projects = collect($openIssues)->groupBy(fn ($i) => $i['project'] ?? '')->map(fn ($g, $k) => [
            'key' => $k === '' ? null : $k, 'name' => $g->first()['project_name'] ?? $k, 'count' => $g->count(),
        ])->sortByDesc('count')->values()->take(12)->all();

        return [
            'granularity' => $granularity,
            'series' => $series,
            'kpis' => [
                'total' => count($issues), 'open' => count($openIssues), 'resolved' => count($issues) - count($openIssues),
                'created_in_range' => $createdInRange, 'resolved_in_range' => $resolvedCount,
                'resolution_rate' => $createdInRange > 0 ? round($resolvedCount / $createdInRange * 100) : null,
                'avg_days' => $durations ? round(array_sum($durations) / count($durations), 1) : null,
                'median_days' => $durations ? round($durations[(int) floor((count($durations) - 1) / 2)], 1) : null,
                'oldest_open_days' => $openIssues ? (int) round(max(array_map($age, $openIssues))) : null,
                'unassigned_open' => count(array_filter($openIssues, fn ($i) => ! $i['assignees'])),
            ],
            'assignees' => $assignees,
            'states' => $states,
            'priorities' => $count($openIssues, 'priority'),
            'types' => $count($openIssues, 'type'),
            'projects' => $projects,
            'aging' => $aging,
            'reporters' => collect($createdInRange > 0 ? array_filter($issues, fn ($i) => $i['created'] >= $rangeFrom) : [])
                ->groupBy(fn ($i) => $i['reporter'] ?? '')->map(fn ($g, $k) => ['name' => $k === '' ? null : $k, 'count' => $g->count()])
                ->sortByDesc('count')->values()->take(8)->all(),
        ];
    }
}
