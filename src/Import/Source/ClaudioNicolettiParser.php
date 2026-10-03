<?php
declare(strict_types=1);

namespace App\Import\Source;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Parses one season block out of a claudionicoletti.eu decade page.
 *
 * The whole document is a single Excel-exported <table>; every season is a
 * contiguous run of <tr> rows starting with a lone "ALBANIA 2023-24"-style
 * heading and ending at the next such heading (or the "KUPA E ..." cup
 * section, which this parser deliberately ignores).
 *
 * Some seasons follow the regular season with a round-robin "Final Four"
 * mini-league among the top finishers (as opposed to a KNOCKOUT
 * "Play-offs for championship" bracket) — its rows are collected
 * separately (SeasonData::$finalFour), never merged into the main
 * $standings, and its own totals/footer row (which can have exactly as
 * many non-empty cells as a real team row once empty cells are filtered
 * out) is rejected by requiring the "name" column not be purely numeric.
 */
final class ClaudioNicolettiParser
{
    private const LABELS_STANDINGS_START = ['Kategoria superiore', 'Regular season'];
    private const IGNORED_PREFIXES = [
        'Rules for classification',
        '5) Goal difference',
        'FK =',
        'KS =',
        'AF =',
    ];

    /**
     * @param string $countryHeading e.g. "ALBANIA" (as it appears before the season label)
     * @param string $seasonLabel    e.g. "2023-24" (source spelling: hyphen, 2-digit end year)
     */
    public function parseSeason(string $html, string $countryHeading, string $seasonLabel): SeasonData
    {
        $rows = $this->extractRows($html);

        $heading = trim($countryHeading . ' ' . $seasonLabel);

        $startIdx = null;
        $endIdx = count($rows);

        foreach ($rows as $i => $cells) {
            if ($startIdx === null) {
                if (($cells[0] ?? null) === $heading) {
                    $startIdx = $i;
                }
                continue;
            }

            $first = $cells[0] ?? '';
            if (str_starts_with($first, $countryHeading . ' ') || str_starts_with($first, 'KUPA')) {
                $endIdx = $i;
                break;
            }
        }

        if ($startIdx === null) {
            throw new RuntimeException(sprintf('Season "%s" not found in source', $heading));
        }

        return $this->parseRows(array_slice($rows, $startIdx, $endIdx - $startIdx));
    }

    /**
     * @return array<int, array<int, string>> one entry per <tr>, non-empty cell texts only
     */
    private function extractRows(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $rows = [];

        foreach ($xpath->query('//table//tr') as $tr) {
            $cells = [];
            foreach ($xpath->query('.//td', $tr) as $td) {
                $text = trim(preg_replace('/\s+/u', ' ', $td->textContent ?? ''));
                if ($text !== '') {
                    $cells[] = $text;
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function parseRows(array $rows): SeasonData
    {
        $standings = [];
        $renames = [];
        $championship = [];
        $relegation = [];
        $promoted = [];
        $finalFour = [];

        $section = null;   // 'standings' | 'finalFour' | 'championship' | 'relegation' | null
        $subStage = null;  // current sub-stage label within championship/relegation
        $venue = null;
        $date = null;

        foreach ($rows as $cells) {
            $first = $cells[0];

            // --- Rename note: "Old name => New name" -------------------
            if (str_contains($first, '=>')) {
                [$from, $to] = array_map('trim', explode('=>', $first, 2));
                $renames[] = ['from' => $from, 'to' => $to];
                continue;
            }

            // --- Section switches ----------------------------------------
            if (in_array($first, self::LABELS_STANDINGS_START, true)) {
                $section = 'standings';
                continue;
            }
            if ($first === 'Final Four') {
                $section = 'finalFour';
                continue;
            }
            if ($first === 'Play-offs for championship') {
                $section = 'championship';
                $subStage = null;
                continue;
            }
            if ($first === 'Promotion/relegation play-off') {
                $section = 'relegation';
                $subStage = null;
                continue;
            }
            if ($first === 'Promoted') {
                $promoted = isset($cells[1])
                    ? array_map('trim', explode(',', $cells[1]))
                    : [];
                continue;
            }
            if ($this->isIgnored($first) || $first === 'Played') {
                continue;
            }

            // --- Standings row: "1", "KF Egnatia ...", played, won, ... --
            // The "name" column is checked not to be purely numeric to
            // reject a totals/footer row: once empty cells are stripped,
            // one can end up with exactly as many cells as a real team row
            // (e.g. a "Final Four" footer like "12 6 0 6 17 17 0 18 132"),
            // but a real team name is never just digits.
            if (($section === 'standings' || $section === 'finalFour')
                && ctype_digit($first)
                && count($cells) >= 9
                && !ctype_digit($cells[1])
            ) {
                $row = [
                    'position' => (int) $cells[0],
                    'name' => $cells[1],
                    'played' => (int) $cells[2],
                    'won' => (int) $cells[3],
                    'drawn' => (int) $cells[4],
                    'lost' => (int) $cells[5],
                    'gf' => (int) $cells[6],
                    'ga' => (int) $cells[7],
                    'gd' => (int) $cells[8],
                    'points' => (int) ($cells[9] ?? 0),
                ];

                if ($section === 'finalFour') {
                    $finalFour[] = $row;
                } else {
                    $standings[] = $row;
                }

                continue;
            }

            // --- Championship / relegation play-off rows ------------------
            if ($section === 'championship' || $section === 'relegation') {
                $rest = $cells;

                // Parenthetical note referring to the previous match
                // (tie-break rule reminder, "qualified as team with the
                // higher ranking", etc.)
                if (str_starts_with($first, '(')) {
                    $bucket = $section === 'championship' ? $championship : $relegation;
                    if ($bucket !== []) {
                        $lastIdx = array_key_last($bucket);
                        $bucket[$lastIdx] = $this->withNote($bucket[$lastIdx], trim($first, "() \t"));
                    }
                    if ($section === 'championship') {
                        $championship = $bucket;
                    } else {
                        $relegation = $bucket;
                    }
                    continue;
                }

                // Sub-stage label, optionally with "(Venue dd.mm.yyyy)"
                if (preg_match('/^(Semifinals|Quarter-finals|3rd place final|Final)\b\s*(.*)$/u', $first, $m)) {
                    $subStage = $m[1];
                    [$venue, $date] = $this->parseVenueDate($m[2]);
                    array_shift($rest);
                }

                if ($rest === [] || !str_contains($rest[0], ' - ')) {
                    continue; // label-only row; the match itself is the next row
                }

                [$home, $away] = array_map('trim', explode(' - ', $rest[0], 2));
                array_shift($rest);

                $match = $this->buildMatch($subStage ?? 'Unknown', $home, $away, $rest, $venue, $date);

                if ($section === 'championship') {
                    $championship[] = $match;
                } else {
                    $relegation[] = $match;
                }
            }
        }

        return new SeasonData($standings, $renames, $championship, $relegation, $promoted, $finalFour);
    }

    /**
     * @param string[] $scoreCells everything on the row after "Home - Away"
     *                             e.g. ["1-0"], ["0-0", "aet (4-3 pen)"], ["3-1", "aet"]
     */
    private function buildMatch(string $stage, string $home, string $away, array $scoreCells, ?string $venue, ?string $date): SeasonMatch
    {
        $scoreText = implode(' ', $scoreCells);

        $goals = null;
        if (preg_match('/(\d+)\s*-\s*(\d+)/', $scoreText, $m)) {
            $goals = [(int) $m[1], (int) $m[2]];
        }

        $aet = str_contains($scoreText, 'aet');

        $penalties = null;
        if (preg_match('/\((\d+)\s*-\s*(\d+)\s*pen\)/i', $scoreText, $m)) {
            $penalties = [(int) $m[1], (int) $m[2]];
        }

        return new SeasonMatch(
            stage: $stage,
            home: $home,
            away: $away,
            homeGoals: $goals[0] ?? null,
            awayGoals: $goals[1] ?? null,
            aet: $aet,
            homePenalties: $penalties[0] ?? null,
            awayPenalties: $penalties[1] ?? null,
            venue: $venue,
            date: $date,
        );
    }

    private function withNote(SeasonMatch $match, string $note): SeasonMatch
    {
        return new SeasonMatch(
            stage: $match->stage,
            home: $match->home,
            away: $match->away,
            homeGoals: $match->homeGoals,
            awayGoals: $match->awayGoals,
            aet: $match->aet,
            homePenalties: $match->homePenalties,
            awayPenalties: $match->awayPenalties,
            venue: $match->venue,
            date: $match->date,
            note: $note,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string} [venue, date (Y-m-d)]
     */
    private function parseVenueDate(string $meta): array
    {
        $meta = trim($meta, "() \t");

        if ($meta === '') {
            return [null, null];
        }

        if (preg_match('/^(.*?)\s+(\d{2})\.(\d{2})\.(\d{4})$/u', $meta, $m)) {
            $venue = trim($m[1], ", \t") ?: null;
            $date = sprintf('%04d-%02d-%02d', (int) $m[4], (int) $m[3], (int) $m[2]);

            return [$venue, $date];
        }

        return [$meta, null];
    }

    private function isIgnored(string $first): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($first, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
