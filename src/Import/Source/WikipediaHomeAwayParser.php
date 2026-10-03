<?php
declare(strict_types=1);

namespace App\Import\Source;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parses the "Home \ Away" results grid(s) of a Wikipedia football-season
 * article into per-team home/away W-D-L-GF-GA splits.
 *
 * A results grid is a square table whose corner cell mentions both "Home"
 * and "Away": rows are home teams (full label), columns are away teams
 * (abbreviations, in the SAME alphabetical order as the rows — the
 * convention used by Wikipedia's standard results-table template; that
 * ordering is what lets a column abbreviation be matched back to a row
 * label at all, since the two spellings otherwise have nothing in
 * common). Some articles split the season into two such grids ("First
 * half of season" / "Second half of season" — each already a complete
 * home-and-away round for every pair, together covering all 4 meetings a
 * pair has in a split-season format like Albania's); this parser sums
 * across every matching grid found on the page, so it works whether
 * there's one grid or several.
 *
 * Verified against the 2023/24 Albania Kategoria Superiore article: the
 * home+away totals computed here reconcile exactly with the official
 * league table (W/D/L/GF/GA) published on the same page.
 */
final class WikipediaHomeAwayParser
{
    /**
     * @return array<string, array{home: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}, away: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}}>
     *         keyed by the team label as it appears as a row in the grid(s)
     */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $stats = [];
        $gridsFound = 0;

        foreach ($xpath->query('//table') as $table) {
            $grid = $this->extractGrid($table, $xpath);
            if ($grid === null) {
                continue;
            }
            $this->accumulate($grid, $stats);
            $gridsFound++;
        }

        if ($gridsFound === 0) {
            throw new RuntimeException('No "Home \\ Away" results grid found on the page.');
        }

        return $stats;
    }

    /**
     * @return array{header: string[], rows: array<int, string[]>}|null
     */
    private function extractGrid(DOMElement $table, DOMXPath $xpath): ?array
    {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $tr) as $cell) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent ?? ''));
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if (count($rows) < 3) {
            return null;
        }

        $corner = strtolower($rows[0][0] ?? '');
        if (!str_contains($corner, 'home') || !str_contains($corner, 'away')) {
            return null;
        }

        $header = array_slice($rows[0], 1);
        $dataRows = array_slice($rows, 1);

        // Must be square: as many data rows as header columns (one per team) —
        // filters out unrelated tables that happen to mention "home"/"away".
        if (count($header) !== count($dataRows) || count($header) < 2) {
            return null;
        }

        return ['header' => $header, 'rows' => $dataRows];
    }

    /**
     * @param array{header: string[], rows: array<int, string[]>} $grid
     * @param array<string, array{home: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}, away: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}}> $stats
     */
    private function accumulate(array $grid, array &$stats): void
    {
        // Column position -> team label (see class docblock: rows and
        // columns share the same alphabetical order in this template).
        $labelByPosition = array_map(static fn (array $row) => $row[0], $grid['rows']);

        foreach ($grid['rows'] as $row) {
            $homeLabel = $row[0];
            $stats[$homeLabel] ??= $this->emptyStats();

            for ($ci = 1; $ci < count($row); $ci++) {
                if (!preg_match('/(\d+)\s*[-–—‐‒−]\s*(\d+)/u', $row[$ci], $m)) {
                    continue; // '—' (not played / diagonal) or an unrecognized cell
                }

                $gf = (int) $m[1];
                $ga = (int) $m[2];

                $awayLabel = $labelByPosition[$ci - 1] ?? null;
                if ($awayLabel === null) {
                    continue;
                }

                $stats[$homeLabel]['home'] = $this->addResult($stats[$homeLabel]['home'], $gf, $ga);

                $stats[$awayLabel] ??= $this->emptyStats();
                $stats[$awayLabel]['away'] = $this->addResult($stats[$awayLabel]['away'], $ga, $gf);
            }
        }
    }

    /**
     * @return array{home: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}, away: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}}
     */
    private function emptyStats(): array
    {
        $zero = ['mp' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'gf' => 0, 'ga' => 0];

        return ['home' => $zero, 'away' => $zero];
    }

    /**
     * @param array{mp:int,w:int,d:int,l:int,gf:int,ga:int} $s
     *
     * @return array{mp:int,w:int,d:int,l:int,gf:int,ga:int}
     */
    private function addResult(array $s, int $gf, int $ga): array
    {
        $s['mp']++;
        $s['gf'] += $gf;
        $s['ga'] += $ga;

        if ($gf > $ga) {
            $s['w']++;
        } elseif ($gf === $ga) {
            $s['d']++;
        } else {
            $s['l']++;
        }

        return $s;
    }
}
