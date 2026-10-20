<?php

namespace App\Services;

use App\Exceptions\TrackerException;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\Log;

/**
 * Look-alike issues for an issue about to be created. Each one gets a title match and, when both sides have a description,
 * a description match; the best signal decides whether it is a probable duplicate (not just a look-alike).
 */
class SimilarIssues
{
    /** Title overlap (%) from which a look-alike counts as a probable duplicate. */
    private const DUPLICATE_MATCH = 80;

    /** ...or a description this much (%) the same, whatever the title says (the same problem is often titled differently). */
    private const DUPLICATE_TEXT = 70;

    /** Words a description needs before it is compared at all (shorter ones are mostly shared boilerplate). */
    private const MIN_TEXT_WORDS = 8;

    /** Candidates read from YouTrack per search (the searches are loose, so most are scored and dropped). */
    private const CANDIDATES = 15;

    /** A candidate is shown when its title or its description is at least this much (%) the same... */
    private const MIN_TITLE = 25;

    private const MIN_TEXT = 35;

    /** ...and at most this many are shown (the closest ones). */
    private const SHOW = 6;

    public function __construct(private readonly YouTrackClient $yt) {}

    /**
     * A warning only, so any failure just yields an empty list.
     *
     * @return list<array<string,mixed>> probable duplicates first, then by match
     */
    public function find(?string $project, ?string $summary, ?string $description): array
    {
        if (blank($project) || blank($summary) || ! YouTrackClient::configured()) {
            return [];
        }

        try {
            $issues = $this->yt->similar($project, $summary, self::CANDIDATES, $description);
        } catch (TrackerException) {
            return [];
        } catch (\Throwable $e) {
            // an unexpected answer from the tracker must never break the request that only wanted a warning
            Log::warning('Similar issues lookup failed', ['message' => $e->getMessage()]);

            return [];
        }

        $myTitle = $this->words($summary);
        $myText = $this->words((string) $description, 3);

        $out = array_map(function ($i) use ($myTitle, $myText) {
            $title = $this->jaccard($myTitle, $this->words($i['summary']));
            $text = $this->cosine($myText, $this->words(mb_substr((string) $i['description'], 0, 3000), 3));
            // (nearly) the same title or the same text makes it a probable duplicate and not just a look-alike
            $duplicate = $title >= self::DUPLICATE_MATCH || ($text !== null && $text >= self::DUPLICATE_TEXT);

            return [
                'id' => $i['id'], 'summary' => $i['summary'], 'state' => $i['state'], 'url' => $i['url'],
                'title_match' => $title, 'description_match' => $text,
                'match' => $duplicate && $text !== null ? max($title, $text) : $title,
                'duplicate' => $duplicate,
            ];
        }, $issues);

        $out = array_filter($out, fn ($i) => $i['duplicate'] || $i['title_match'] >= self::MIN_TITLE || ($i['description_match'] !== null && $i['description_match'] >= self::MIN_TEXT));
        usort($out, fn ($a, $b) => [$b['duplicate'], $b['match']] <=> [$a['duplicate'], $a['match']]);

        return array_slice(array_values($out), 0, self::SHOW);
    }

    /** @return list<string> distinct lowercase words of a text, without the app's title prefix */
    private function words(string $text, int $min = 1): array
    {
        $prefix = (string) config('services.youtrack.title_prefix');
        if ($prefix !== '' && str_starts_with($text, $prefix)) {
            $text = ltrim(substr($text, strlen($prefix)), ' -');
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= $min)));
    }

    /** % of words two titles share (common / all different words). */
    private function jaccard(array $a, array $b): int
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union ? (int) round(count(array_intersect($a, $b)) / $union * 100) : 0;
    }

    /** % of words two texts share, not punished for one being longer; null when either has too little text to compare. */
    private function cosine(array $a, array $b): ?int
    {
        if (count($a) < self::MIN_TEXT_WORDS || count($b) < self::MIN_TEXT_WORDS) {
            return null;
        }

        return (int) round(count(array_intersect($a, $b)) / sqrt(count($a) * count($b)) * 100);
    }
}
