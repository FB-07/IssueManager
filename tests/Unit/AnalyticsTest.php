<?php

use App\Services\Analytics;
use Carbon\CarbonImmutable;

function ms(string $date): int
{
    return CarbonImmutable::parse($date, Analytics::TZ)->getTimestamp() * 1000;
}

function row(string $created, ?string $resolved, array $assignees, string $project = 'DEV', ?string $state = 'Open'): array
{
    return ['created' => ms($created), 'resolved' => $resolved ? ms($resolved) : null, 'project' => $project, 'project_name' => $project, 'reporter' => 'Eu', 'assignees' => $assignees, 'state' => $state, 'type' => 'Bug', 'priority' => 'Normal'];
}

it('counts what was created and resolved in each week, and the backlog left open at its end', function () {
    $now = CarbonImmutable::parse('2026-10-21 12:00', Analytics::TZ); // a Wednesday
    $issues = [
        row('2026-10-19 09:00', '2026-10-20 09:00', ['Ana'], state: 'Done'),   // this week: created and resolved
        row('2026-10-12 09:00', null, ['Ana']),                                   // last week, still open
        row('2026-09-01 09:00', '2026-10-14 09:00', ['Ana', 'Rui'], state: 'Done'), // resolved last week
    ];

    $a = Analytics::build($issues, 'week', $now);

    expect($a['series'])->toHaveCount(12);
    expect($a['series'][11])->toMatchArray(['start' => '2026-10-19', 'created' => 1, 'resolved' => 1, 'open' => 1]);
    expect($a['series'][10])->toMatchArray(['start' => '2026-10-12', 'created' => 1, 'resolved' => 1, 'open' => 1]);
    expect($a['kpis'])->toMatchArray(['total' => 3, 'open' => 1, 'resolved' => 2, 'created_in_range' => 3, 'resolved_in_range' => 2, 'resolution_rate' => 67]);
});

it('lists who has what open, counting an issue for each of its assignees', function () {
    $now = CarbonImmutable::parse('2026-10-21 12:00', Analytics::TZ);
    $issues = [
        row('2026-10-12 09:00', null, ['Ana']),
        row('2026-10-13 09:00', null, ['Ana', 'Rui']),
        row('2026-10-14 09:00', null, []),
        row('2026-10-01 09:00', '2026-10-15 09:00', ['Rui'], state: 'Done'),
    ];

    $people = collect(Analytics::build($issues, 'month', $now)['assignees'])->keyBy(fn ($p) => $p['name'] ?? '-');

    expect($people['Ana']['open'])->toBe(2)->and($people['Rui']['open'])->toBe(1)->and($people['Rui']['resolved'])->toBe(1)->and($people['-']['open'])->toBe(1);
    expect($people['Ana']['oldest'])->toBe(9);
});

it('spreads the open issues by age, state, priority and project, over months and years too', function () {
    $now = CarbonImmutable::parse('2026-10-21 12:00', Analytics::TZ);
    $issues = [row('2026-10-18 09:00', null, ['Ana']), row('2026-08-01 09:00', null, ['Ana'], 'ABC'), row('2025-01-01 09:00', null, ['Ana'], 'ABC')];

    foreach (['month' => 12, 'year' => 5, 'nonsense' => 12] as $g => $n) {
        expect(Analytics::build($issues, $g, $now)['series'])->toHaveCount($n);
    }
    $a = Analytics::build($issues, 'year', $now);

    expect(array_column($a['aging'], 'count'))->toBe([1, 0, 1, 0, 1])
        ->and($a['projects'][0])->toMatchArray(['key' => 'ABC', 'count' => 2])
        ->and($a['priorities'][0])->toMatchArray(['name' => 'Normal', 'count' => 3]);
});
