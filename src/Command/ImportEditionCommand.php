<?php
declare(strict_types=1);

namespace App\Command;

use App\Core\EventDispatcher;
use App\Event\EntityCreatedEvent;
use App\Import\Source\ClaudioNicolettiFetcher;
use App\Import\Source\ClaudioNicolettiParser;
use App\Import\Source\SeasonData;
use App\Import\Source\SeasonMatch;
use App\Import\Source\WikipediaFetcher;
use App\Import\Source\WikipediaHomeAwayParser;
use App\Import\StageResolver;
use App\Import\TeamInteractiveResolver;
use App\Import\TeamResolver;
use App\Import\UnresolvedTeamException;
use App\Import\WikipediaTeamMatcher;
use App\Query\SqlQueryHandler;
use PDO;
use RuntimeException;
use Throwable;

/**
 * `php bin/console import:edition "tournament_id=13 country=ALBANIA season=2023-24 lang=sq iso=AL source_url=https://www.claudionicoletti.eu/soccer-statistics/campionati-nazionali-in-europa/all-final-tables/albania-league-final-tables/alb-2021-30/ wikipedia_url=https://en.wikipedia.org/wiki/2023-24_Kategoria_Superiore"`
 *
 * Imports one season's final table (+ championship play-off, + the final
 * of the promotion/relegation play-off, + club renames, + the champion)
 * from a claudionicoletti.eu "all final tables" page into `edition`,
 * `standing`, `game`, `note`, `competition_entry`, `team_name` and
 * `winner`.
 *
 * For a scraped name that cannot be matched with confidence, and the
 * process is running at an interactive terminal, the user is prompted
 * (see TeamInteractiveResolver) to say whether it is a brand new club or a
 * rename of one already on file. In any non-interactive context, or if the
 * user chooses to skip, the import stops and reports every unresolved name
 * instead, before committing anything to the database (see README
 * "Nouvelles équipes").
 *
 * SOURCES: each source is used for what it's actually good for, rather
 * than picking one "master" source per edition:
 *
 *  - claudionicoletti.eu (`source_url`, required) provides the season's
 *    overall standings (position, W/D/L/GF/GA/Pts), the championship
 *    play-off and promotion/relegation play-off matches, and club renames.
 *
 *  - Wikipedia (`wikipedia_url`, optional) provides the home/away split
 *    (hw/hd/hl/hgf/hga/aw/ad/al/agf/aga) via the "Home \ Away" results
 *    grid(s) published on the season's article. Soccerway was considered
 *    for this but rejected: its standings/home/away tables are computed
 *    client-side against an internal GraphQL API (persisted queries with
 *    opaque hashes) — a plain HTTP GET never sees the numbers, only a
 *    real browser does. Wikipedia's tables, by contrast, are plain
 *    server-rendered HTML. When `wikipedia_url` is omitted, or a team
 *    can't be matched between the two sources, the home/away columns for
 *    that team are simply left empty — never blocks the import.
 *
 * A season can end its regular season with a round-robin "Final Four"
 * mini-league (SeasonData::$finalFour) instead of, or in addition to, a
 * knockout play-off — it gets its own `stage` ("Final Four") and its own
 * `standing` rows. `teams_number` is NOT the row count of that stage: it's
 * the edition-wide team count (the regular season's), repeated identically
 * on every stage's rows — mirrors the existing convention for e.g. a
 * "Championship group"/"Relegation group" split (see StageResolver).
 */
final class ImportEditionCommand
{
    public function __construct(
        private PDO $pdo,
        private SqlQueryHandler $db,
        private ClaudioNicolettiFetcher $fetcher,
        private ClaudioNicolettiParser $parser,
        private WikipediaFetcher $wikipediaFetcher,
        private WikipediaHomeAwayParser $wikipediaParser,
        private WikipediaTeamMatcher $wikipediaMatcher,
        private TeamResolver $teams,
        private TeamInteractiveResolver $interactive,
        private StageResolver $stages,
        private EventDispatcher $dispatcher,
    ) {
    }

    public function edition(array $params = []): void
    {
        foreach (['tournament_id', 'country', 'season', 'lang', 'iso', 'source_url'] as $required) {
            if (!isset($params[$required]) || $params[$required] === '') {
                throw new RuntimeException(sprintf(
                    'Missing required parameter "%s". Usage: import:edition '
                    . 'tournament_id=13 country=ALBANIA season=2023-24 lang=sq iso=AL source_url=... '
                    . '[wikipedia_url=...]',
                    $required
                ));
            }
        }

        $tournamentId = (int) $params['tournament_id'];
        $country = $params['country'];
        $seasonLabel = $params['season'];
        $lang = $params['lang'];
        $iso = strtoupper($params['iso']);
        $sourceUrl = $params['source_url'];
        $wikipediaUrl = $params['wikipedia_url'] ?? null;
        $editionName = str_replace('-', '/', $seasonLabel);

        $tournament = $this->db->fetchOne(sprintf(
            'SELECT id, team_id FROM tournament WHERE id = %d',
            $tournamentId
        ));

        if ($tournament === null) {
            throw new RuntimeException("Tournament $tournamentId not found");
        }

        $federationTeamId = (int) $tournament['team_id'];

        $existing = $this->db->fetchOne(sprintf(
            "SELECT id FROM edition WHERE tournament_id = %d AND name = '%s'",
            $tournamentId,
            addslashes($editionName)
        ));

        if ($existing !== null) {
            throw new RuntimeException(sprintf(
                'Edition "%s" already exists for tournament %d (id=%d) — aborting to avoid duplicates.',
                $editionName,
                $tournamentId,
                $existing['id']
            ));
        }

        echo sprintf("Fetching %s...\n", $sourceUrl);
        $html = $this->fetcher->fetchHtml($sourceUrl);

        $season = $this->parser->parseSeason($html, $country, $seasonLabel);

        echo sprintf(
            "Parsed: %d standings rows, %d rename note(s), %d championship play-off match(es), %d relegation play-off match(es)\n",
            count($season->standings),
            count($season->renames),
            count($season->championshipPlayoff),
            count($season->relegationPlayoff)
        );

        $homeAway = [];
        if ($wikipediaUrl !== null && $wikipediaUrl !== '') {
            $homeAway = $this->buildHomeAwayMap($wikipediaUrl, $season);
        }

        // --- Resolve every team name, then write everything else -----------
        // Both are wrapped in ONE transaction. Resolving a name can itself
        // write (a confirmed "new team" or "rename" from TeamInteractiveResolver
        // hits `team`/`team_name` right away — TeamResolver has no notion of
        // a pending write). So if the run is aborted afterwards — another
        // name stays unresolved, or any later step throws — rolling back
        // must undo those writes too, to keep the "nothing was written"
        // guarantee intact even when some names were resolved interactively.
        $this->pdo->beginTransaction();

        try {
            [$standingTeams, $championshipTeams, $relegationFinal, $unresolved, $interactiveRenames, $finalFourTeams]
                = $this->resolveAllTeams($season, $federationTeamId, $lang, $iso);

            if ($unresolved !== []) {
                $this->pdo->rollBack();

                echo "\nSTOPPED — the following team names could not be resolved with confidence:\n";
                foreach ($unresolved as $name => $exception) {
                    echo sprintf(" - %s\n", $exception->getMessage());
                }
                echo "\nNothing was written. Resolve these manually (alias, rename, or new team) and re-run.\n";

                return;
            }

            // Matches reference teams from either bucket (a club can be both in
            // the final table and in a play-off), so merge them for anything
            // that inserts a `game` row.
            $allTeams = $standingTeams + $championshipTeams;

            $requiredStages = $this->stages->requiredStages($season);
            $stageIds = $this->stages->resolve($requiredStages, $tournamentId);

            $editionId = $this->createEdition($tournamentId, $editionName, $stageIds['template_id']);

            // teams_number is edition-wide (the regular season's count),
            // reused as-is on every stage's rows — never the row count of
            // that particular stage (see class docblock).
            $teamsNumber = count($season->standings);

            $this->importStandings($season, $standingTeams, $homeAway, $teamsNumber, $stageIds['stages']['Final Table'], $editionId);

            if ($season->finalFour !== []) {
                $this->importFinalFour($season, $finalFourTeams, $teamsNumber, $stageIds['stages']['Final Four'], $editionId);
            }

            $this->importChampionshipPlayoff($season, $allTeams, $stageIds['stages'], $editionId);

            if ($relegationFinal !== null) {
                $gameId = $this->insertGame($relegationFinal, $allTeams, $stageIds['stages']['Promotion/relegation play-off'], $editionId);
                $this->fire('game', $gameId);
            }

            $this->applyRenames($season, $federationTeamId, $lang, $iso, $stageIds['stages']['Final Table'], $editionId);
            $this->insertRenameNotes($interactiveRenames, $stageIds['stages']['Final Table'], $editionId);

            $this->recordWinner($season, $standingTeams, $championshipTeams, $finalFourTeams, $editionId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        echo sprintf("DONE — edition %d created for tournament %d (%s).\n", $editionId, $tournamentId, $editionName);
    }

    /**
     * Fetches and parses the Wikipedia "Home \ Away" results grid(s) and
     * matches each row/team back to the claudionicoletti.eu standings name
     * for this same edition. Never fatal: a fetch/parse failure, or a team
     * that can't be matched on either side, is reported and simply leaves
     * that team's home/away columns empty — this is enrichment, not a
     * required source.
     *
     * @return array<string, array{home: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}, away: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}}>
     *         keyed by the SAME team names used in $season->standings
     */
    private function buildHomeAwayMap(string $wikipediaUrl, SeasonData $season): array
    {
        echo sprintf("Fetching %s (home/away)...\n", $wikipediaUrl);

        try {
            $html = $this->wikipediaFetcher->fetchHtml($wikipediaUrl);
            $byLabel = $this->wikipediaParser->parse($html);
        } catch (Throwable $e) {
            echo sprintf("  (!) Wikipedia home/away import skipped: %s\n", $e->getMessage());

            return [];
        }

        $labels = array_keys($byLabel);
        $map = [];
        $claimedBy = [];

        foreach ($season->standings as $row) {
            $label = $this->wikipediaMatcher->match($row['name'], $labels);

            if ($label === null) {
                echo sprintf("  (!) No Wikipedia home/away match for \"%s\" — hw/hd/hl/... left empty.\n", $row['name']);
                continue;
            }

            if (isset($claimedBy[$label])) {
                echo sprintf(
                    "  (!) Wikipedia label \"%s\" matched both \"%s\" and \"%s\" — ignoring for both (ambiguous).\n",
                    $label,
                    $claimedBy[$label],
                    $row['name']
                );
                unset($map[$claimedBy[$label]]);
                continue;
            }

            $claimedBy[$label] = $row['name'];
            $map[$row['name']] = $byLabel[$label];
        }

        return $map;
    }

    /**
     * Resolves every team name referenced anywhere in $season. A name that
     * can't be matched automatically is offered to TeamInteractiveResolver
     * first (no-op outside an interactive terminal); only if that also
     * fails to produce an answer does it land in $unresolved.
     *
     * @return array{0: array<string, array{team_name_id:int,team_id:int,fuzzy:bool}>,
     *               1: array<string, array{team_name_id:int,team_id:int,fuzzy:bool}>,
     *               2: ?SeasonMatch,
     *               3: array<string, UnresolvedTeamException>,
     *               4: array<int, array{from:string,to:string}>,
     *               5: array<string, array{team_name_id:int,team_id:int,fuzzy:bool}>}
     */
    private function resolveAllTeams(SeasonData $season, int $federationTeamId, string $lang, string $iso): array
    {
        $standingTeams = [];
        $championshipTeams = [];
        $finalFourTeams = [];
        $unresolved = [];
        $interactiveRenames = [];

        $resolve = function (string $name) use ($federationTeamId, $lang, $iso, &$unresolved, &$standingTeams, &$interactiveRenames): ?array {
            if (isset($standingTeams[$name])) {
                return $standingTeams[$name];
            }

            try {
                return $this->teams->resolve($name, $federationTeamId);
            } catch (UnresolvedTeamException $e) {
                $resolved = $this->interactive->resolve($e, $federationTeamId, $lang, $iso);

                if ($resolved === null) {
                    $unresolved[$name] = $e;

                    return null;
                }

                if ($resolved['renamed_from'] !== null) {
                    $interactiveRenames[] = ['from' => $resolved['renamed_from'], 'to' => $name];
                }

                return $resolved;
            }
        };

        foreach ($season->standings as $row) {
            $resolved = $resolve($row['name']);
            if ($resolved !== null) {
                $standingTeams[$row['name']] = $resolved;
            }
        }

        // Final Four participants are drawn from the regular season (same
        // names), so this is normally a cache hit on $standingTeams — but a
        // fresh resolve() is still attempted for safety.
        foreach ($season->finalFour as $row) {
            if (!isset($finalFourTeams[$row['name']])) {
                $resolved = $standingTeams[$row['name']] ?? $resolve($row['name']);
                if ($resolved !== null) {
                    $finalFourTeams[$row['name']] = $resolved;
                }
            }
        }

        foreach ($season->championshipPlayoff as $match) {
            foreach ([$match->home, $match->away] as $name) {
                if (!isset($championshipTeams[$name])) {
                    $resolved = $standingTeams[$name] ?? $resolve($name);
                    if ($resolved !== null) {
                        $championshipTeams[$name] = $resolved;
                    }
                }
            }
        }

        // Only the FINAL of the promotion/relegation play-off concerns this
        // tournament (see class docblock) — the quarter/semi rounds are
        // played entirely among lower-division clubs.
        $relegationFinal = null;
        foreach ($season->relegationPlayoff as $match) {
            if ($match->stage === 'Final') {
                $relegationFinal = $match;
            }
        }

        if ($relegationFinal !== null) {
            foreach ([$relegationFinal->home, $relegationFinal->away] as $name) {
                if (!isset($standingTeams[$name]) && !isset($championshipTeams[$name])) {
                    $resolved = $resolve($name);
                    if ($resolved !== null) {
                        $championshipTeams[$name] = $resolved;
                    }
                }
            }
        }

        return [$standingTeams, $championshipTeams, $relegationFinal, $unresolved, $interactiveRenames, $finalFourTeams];
    }

    private function createEdition(int $tournamentId, string $editionName, int $templateId): int
    {
        $sequence = (int) $this->pdo->query(sprintf(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM edition WHERE tournament_id = %d',
            $tournamentId
        ))->fetchColumn();

        $editionId = $this->db->insert('edition', [
            'sequence' => $sequence,
            'name' => $editionName,
            'is_link' => 1,
            'unofficial' => 0,
            'awarded' => 1,
            'is_current' => 0,
            'tournament_id' => $tournamentId,
            'template_id' => $templateId,
        ]);

        $this->fire('edition', $editionId);

        return $editionId;
    }

    /**
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $teams
     * @param array<string, array{home: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}, away: array{mp:int,w:int,d:int,l:int,gf:int,ga:int}}> $homeAway
     */
    private function importStandings(SeasonData $season, array $teams, array $homeAway, int $teamsNumber, int $finalTableStageId, int $editionId): void
    {
        foreach ($season->standings as $row) {
            $team = $teams[$row['name']];

            $data = [
                'teams_number' => $teamsNumber,
                'position' => $row['position'],
                'team_name_id' => $team['team_name_id'],
                'stage_id' => $finalTableStageId,
                'edition_id' => $editionId,
                'w' => $row['won'],
                'd' => $row['drawn'],
                'l' => $row['lost'],
                'gf' => $row['gf'],
                'ga' => $row['ga'],
                'pts' => $row['points'],
                'is_alltime' => 1,
                'is_reference' => 1,
            ];

            if (isset($homeAway[$row['name']])) {
                $ha = $homeAway[$row['name']];
                $data += [
                    'hw' => $ha['home']['w'],
                    'hd' => $ha['home']['d'],
                    'hl' => $ha['home']['l'],
                    'hgf' => $ha['home']['gf'],
                    'hga' => $ha['home']['ga'],
                    'aw' => $ha['away']['w'],
                    'ad' => $ha['away']['d'],
                    'al' => $ha['away']['l'],
                    'agf' => $ha['away']['gf'],
                    'aga' => $ha['away']['ga'],
                ];
            }

            $standingId = $this->db->insert('standing', $data);

            $this->fire('standing', $standingId);

            if ($team['fuzzy']) {
                echo sprintf(
                    "  (!) \"%s\" matched fuzzily to team_name_id=%d (\"%s\") — please double-check.\n",
                    $row['name'],
                    $team['team_name_id'],
                    $team['name'] ?? '?'
                );
            }
        }
    }

    /**
     * Imports the "Final Four" round-robin table: same columns as the main
     * standings, under their own stage, but sharing the EDITION-WIDE
     * $teamsNumber (see class docblock) — never count($season->finalFour).
     * No home/away split here: Wikipedia's grid covers the regular season
     * only, so applying it to a Final Four row would mix stats from two
     * different stages.
     *
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $teams
     */
    private function importFinalFour(SeasonData $season, array $teams, int $teamsNumber, int $stageId, int $editionId): void
    {
        foreach ($season->finalFour as $row) {
            $team = $teams[$row['name']];

            $standingId = $this->db->insert('standing', [
                'teams_number' => $teamsNumber,
                'position' => $row['position'],
                'team_name_id' => $team['team_name_id'],
                'stage_id' => $stageId,
                'edition_id' => $editionId,
                'w' => $row['won'],
                'd' => $row['drawn'],
                'l' => $row['lost'],
                'gf' => $row['gf'],
                'ga' => $row['ga'],
                'pts' => $row['points'],
                'is_alltime' => 1,
                'is_reference' => 1,
            ]);

            $this->fire('standing', $standingId);

            if ($team['fuzzy']) {
                echo sprintf(
                    "  (!) \"%s\" matched fuzzily to team_name_id=%d (\"%s\") — please double-check.\n",
                    $row['name'],
                    $team['team_name_id'],
                    $team['name'] ?? '?'
                );
            }
        }
    }

    /**
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $teams
     * @param array<string,int> $stageIds
     */
    private function importChampionshipPlayoff(SeasonData $season, array $teams, array $stageIds, int $editionId): void
    {
        $stageNameFor = static fn (string $sourceStage): string => match ($sourceStage) {
            '3rd place final' => 'Third place',
            default => $sourceStage, // 'Semifinals', 'Final'
        };

        foreach ($season->championshipPlayoff as $match) {
            $stageId = $stageIds[$stageNameFor($match->stage)] ?? null;

            if ($stageId === null) {
                continue;
            }

            $gameId = $this->insertGame($match, $teams, $stageId, $editionId);

            if ($match->note !== null) {
                $this->db->insert('note', [
                    'value' => $match->note,
                    'start' => 0,
                    'edition_id' => $editionId,
                    'stage_id' => $stageId,
                ]);
            }

            $this->fire('game', $gameId);
        }
    }

    private function applyRenames(SeasonData $season, int $federationTeamId, string $lang, string $iso, int $finalTableStageId, int $editionId): void
    {
        foreach ($season->renames as $rename) {
            $this->teams->applyRename($rename['from'], $rename['to'], $federationTeamId, $lang, $iso);
        }

        $this->insertRenameNotes($season->renames, $finalTableStageId, $editionId);
    }

    /**
     * Shared by $season->renames (announced by the source) and
     * $interactiveRenames (confirmed by the user at the prompt): either way
     * a rename gets exactly one `note` row on the Final Table stage.
     *
     * @param array<int, array{from:string,to:string}> $renames
     */
    private function insertRenameNotes(array $renames, int $finalTableStageId, int $editionId): void
    {
        foreach ($renames as $rename) {
            $this->db->insert('note', [
                'value' => sprintf('%s => %s', $rename['from'], $rename['to']),
                'start' => 0,
                'edition_id' => $editionId,
                'stage_id' => $finalTableStageId,
            ]);
        }
    }

    /**
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $standingTeams
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $championshipTeams
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $finalFourTeams
     */
    private function recordWinner(SeasonData $season, array $standingTeams, array $championshipTeams, array $finalFourTeams, int $editionId): void
    {
        $championName = null;

        foreach ($season->championshipPlayoff as $match) {
            if ($match->stage === 'Final') {
                $winner = $match->winner();
                if ($winner === 1) {
                    $championName = $match->home;
                } elseif ($winner === 2) {
                    $championName = $match->away;
                }
            }
        }

        if ($championName === null && $season->finalFour !== []) {
            // A round-robin Final Four decides the title directly (no
            // knockout final): whoever tops ITS table is champion, which
            // can differ from the regular-season leader.
            foreach ($season->finalFour as $row) {
                if ($row['position'] === 1) {
                    $championName = $row['name'];
                }
            }
        }

        if ($championName === null) {
            // No championship play-off and no Final Four (or an undecided
            // final, which shouldn't happen): the league leader is champion.
            foreach ($season->standings as $row) {
                if ($row['position'] === 1) {
                    $championName = $row['name'];
                }
            }
        }

        if ($championName === null) {
            return;
        }

        $team = $finalFourTeams[$championName] ?? $championshipTeams[$championName] ?? $standingTeams[$championName] ?? null;

        if ($team === null) {
            return;
        }

        $this->db->insert('winner', [
            'note' => null,
            'team_name_id' => $team['team_name_id'],
            'edition_id' => $editionId,
        ]);
    }

    /**
     * @param array<string, array{team_name_id:int,team_id:int,fuzzy:bool}> $teams
     */
    private function insertGame(SeasonMatch $match, array $teams, int $stageId, int $editionId): int
    {
        $home = $teams[$match->home];
        $away = $teams[$match->away];

        $gameId = $this->db->insert('game', [
            'game_date' => $match->date,
            'team1_id' => $home['team_id'],
            'team2_id' => $away['team_id'],
            'gf' => $match->homeGoals,
            'ga' => $match->awayGoals,
            'stage_id' => $stageId,
            'edition_id' => $editionId,
            'team_winner' => $match->winner(),
            'city' => $match->venue,
            'is_official' => 1,
        ]);

        if ($match->aet) {
            $note = $match->homePenalties !== null
                ? sprintf('[aet] (%d-%d pen)', $match->homePenalties, $match->awayPenalties)
                : '[aet]';

            $this->db->insert('competition_entry', [
                'note' => $note,
                'standings_id' => null,
                'game_id' => $gameId,
                'stage_id' => $stageId,
                'edition_id' => $editionId,
            ]);
        }

        return $gameId;
    }

    private function fire(string $entity, int $id): void
    {
        try {
            $this->dispatcher->dispatch(new EntityCreatedEvent($entity, $id));
        } catch (Throwable $e) {
            echo sprintf("  (!) indexing %s#%d failed: %s\n", $entity, $id, $e->getMessage());
        }
    }
}
