<?php
declare(strict_types=1);

namespace App\Import;

/**
 * Matches a short team label as it appears in a Wikipedia results grid
 * (e.g. "Dinamo", "Tirana", "Kukesi") against the fuller official names
 * already resolved for this edition from another source (e.g.
 * "KS Dinamo Tirane", "KF Tirana", "KS Kukesi").
 *
 * Deliberately does NOT touch the database: it only reconciles two sets of
 * strings for the ~10-20 teams of a single edition — a much smaller and
 * safer search space than resolving against every `team_name` in a
 * federation (see TeamResolver for that). A plain Levenshtein comparison
 * of the full strings doesn't work here (short label vs. fuller official
 * name differ mostly in LENGTH, e.g. "Dinamo" vs. "Dinamo City"), so this
 * matches by containment after normalizing first, falling back to
 * Levenshtein only as a last resort.
 */
final class WikipediaTeamMatcher
{
    /** Levenshtein distance (last-resort fallback only) at or below which a match is trusted. */
    private const FUZZY_THRESHOLD = 3;

    /**
     * @param string[] $candidates
     */
    public function match(string $label, array $candidates): ?string
    {
        $target = $this->normalize($label);

        foreach ($candidates as $candidate) {
            if ($this->normalize($candidate) === $target) {
                return $candidate;
            }
        }

        $containing = [];
        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalize($candidate);
            if (str_contains($normalizedCandidate, $target) || str_contains($target, $normalizedCandidate)) {
                $containing[] = $candidate;
            }
        }

        if (count($containing) === 1) {
            return $containing[0];
        }

        if (count($containing) > 1) {
            usort($containing, fn (string $a, string $b) => $this->distance($target, $a) <=> $this->distance($target, $b));

            return $containing[0];
        }

        $best = null;
        $bestDistance = null;
        foreach ($candidates as $candidate) {
            $distance = $this->distance($target, $candidate);
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return ($best !== null && $bestDistance <= self::FUZZY_THRESHOLD) ? $best : null;
    }

    private function distance(string $normalizedTarget, string $candidate): int
    {
        return levenshtein($normalizedTarget, $this->normalize($candidate));
    }

    private function normalize(string $name): string
    {
        $name = preg_replace('/^(KF|FK|KS|AF|F\.K\.|K\.F\.|K\.S\.)\s+/ui', '', trim($name)) ?? $name;
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return strtolower(trim($transliterated !== false ? $transliterated : $name));
    }
}
