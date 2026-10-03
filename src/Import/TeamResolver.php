<?php
declare(strict_types=1);

namespace App\Import;

use App\Query\SqlQueryHandler;
use PDO;

/**
 * Resolves a team name as it appears in a scraped source to the
 * `team_name` row currently in effect (last_version = 1) for that
 * federation, and applies club renames announced by the source.
 *
 * Never creates a new `team`/`team_name` on its own INITIATIVE: a name
 * that doesn't match anything closely enough throws
 * UnresolvedTeamException so the caller can decide — stop and report it
 * (the default, non-interactive behavior), or ask a human via
 * TeamInteractiveResolver, which then calls createTeam() (brand new club)
 * or applyRename() (a name change for a club already on file) explicitly.
 */
final class TeamResolver
{
    /** Levenshtein distance (on normalized names) at or below which a fuzzy match is trusted. */
    private const FUZZY_THRESHOLD = 2;

    public function __construct(
        private PDO $pdo,
        private SqlQueryHandler $db,
    ) {
    }

    /**
     * @return array{team_name_id:int,team_id:int,name:string,fuzzy:bool}
     *
     * @throws UnresolvedTeamException
     */
    public function resolve(string $scrapedName, int $federationTeamId): array
    {
        $exact = $this->findCurrentByName($scrapedName, $federationTeamId);

        if ($exact !== null) {
            return $exact + ['fuzzy' => false];
        }

        $candidates = $this->currentTeamNames($federationTeamId);
        $normalizedTarget = $this->normalize($scrapedName);

        $scored = [];
        foreach ($candidates as $candidate) {
            $scored[] = [
                'team_name_id' => (int) $candidate['id'],
                'team_id' => (int) $candidate['team_id'],
                'name' => $candidate['name'],
                'distance' => levenshtein($normalizedTarget, $this->normalize($candidate['name'])),
            ];
        }

        usort($scored, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

        $best = $scored[0] ?? null;

        if ($best !== null && $best['distance'] <= self::FUZZY_THRESHOLD) {
            return [
                'team_name_id' => $best['team_name_id'],
                'team_id' => $best['team_id'],
                'name' => $best['name'],
                'fuzzy' => true,
            ];
        }

        throw new UnresolvedTeamException($scrapedName, array_slice($scored, 0, 5));
    }

    /**
     * Applies a "Old name => New name" rename announced by the source.
     *
     * IMPORTANT: this only rotates `team_name` (closes the old version,
     * opens the new one) so that FUTURE editions resolve to the new name.
     * It deliberately does NOT touch the edition being imported right now:
     * on claudionicoletti.eu, a rename note attached to season N documents
     * a name change that takes effect from season N+1 onward — season N's
     * own standings still show the old name. Resolve $from BEFORE calling
     * this, using the id it returns for the current edition's rows.
     *
     * @return int the new team_name_id (last_version = 1 after the call)
     */
    public function applyRename(string $from, string $to, int $federationTeamId, string $language, string $country): int
    {
        $old = $this->resolve($from, $federationTeamId);

        $this->db->update('team_name', $old['team_name_id'], ['last_version' => 0]);

        $nextVersion = (int) ($this->pdo->query(sprintf(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM team_name WHERE team_id = %d',
            $old['team_id']
        ))->fetchColumn());

        return $this->db->insert('team_name', [
            'name' => $to,
            'version' => $nextVersion,
            'last_version' => 1,
            'language' => $language,
            'country' => $country,
            'team_id' => $old['team_id'],
            'team_parent_id' => $federationTeamId,
            'geoname_id' => $this->guessGeonameId($to, $country) ?? $this->currentGeonameId($old['team_name_id']),
        ]);
    }

    /**
     * Creates a brand new club: a `team` row (level 3, under the given
     * federation, with `team.name` also set to $name on explicit request —
     * every other club currently on file has `team.name = NULL` and relies
     * solely on `team_name` for its name history) plus its first
     * `team_name` (version 1), optionally with `official_name`/`full_name`
     * when the caller has them. Used only when a human has confirmed, via
     * TeamInteractiveResolver, that a scraped name genuinely isn't any club
     * already on file — TeamResolver itself never decides this on its own.
     *
     * @return array{team_name_id:int,team_id:int,name:string,fuzzy:bool}
     */
    public function createTeam(
        string $name,
        int $federationTeamId,
        string $language,
        string $country,
        int $geonameId,
        ?string $officialName = null,
        ?string $fullName = null,
    ): array {
        $teamId = $this->db->insert('team', [
            'level' => 3,
            'name' => $name,
            'is_active' => 1,
            'team_parent_id' => $federationTeamId,
        ]);

        $teamNameId = $this->db->insert('team_name', [
            'name' => $name,
            'official_name' => $officialName,
            'full_name' => $fullName,
            'version' => 1,
            'last_version' => 1,
            'language' => $language,
            'country' => $country,
            'team_id' => $teamId,
            'team_parent_id' => $federationTeamId,
            'geoname_id' => $geonameId,
        ]);

        return ['team_name_id' => $teamNameId, 'team_id' => $teamId, 'name' => $name, 'fuzzy' => false];
    }

    /**
     * Looks up a city in `geoname` by exact name. Public so
     * TeamInteractiveResolver can validate a city name (and retry) before
     * calling createTeam(), which requires a geoname_id up front (the
     * column is NOT NULL).
     */
    public function findGeonameId(string $cityName): ?int
    {
        $cityName = trim($cityName);

        if ($cityName === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM geoname WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $cityName]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * @return array{team_name_id:int,team_id:int,name:string}|null
     */
    private function findCurrentByName(string $name, int $federationTeamId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tn.id AS team_name_id, tn.team_id, tn.name
             FROM team_name tn
             INNER JOIN team t ON t.id = tn.team_id
             WHERE tn.last_version = 1 AND t.team_parent_id = :parent AND tn.name = :name
             LIMIT 1'
        );
        $stmt->execute(['parent' => $federationTeamId, 'name' => $name]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'team_name_id' => (int) $row['team_name_id'],
            'team_id' => (int) $row['team_id'],
            'name' => $row['name'],
        ];
    }

    /**
     * @return array<int, array{id:int,team_id:int,name:string}>
     */
    private function currentTeamNames(int $federationTeamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tn.id, tn.team_id, tn.name
             FROM team_name tn
             INNER JOIN team t ON t.id = tn.team_id
             WHERE tn.last_version = 1 AND t.team_parent_id = :parent'
        );
        $stmt->execute(['parent' => $federationTeamId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function currentGeonameId(int $teamNameId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT geoname_id FROM team_name WHERE id = :id');
        $stmt->execute(['id' => $teamNameId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Best-effort city guess from a "KF/FK/KS <Club> <City>" name, resolved
     * against `geoname` for the given country. Returns null rather than a
     * wrong guess when nothing matches confidently.
     */
    private function guessGeonameId(string $clubName, string $country): ?int
    {
        $withoutPrefix = preg_replace('/^(KF|FK|KS|AF)\s+/u', '', trim($clubName));
        $words = preg_split('/\s+/u', (string) $withoutPrefix) ?: [];

        if ($words === []) {
            return null;
        }

        return $this->findGeonameId((string) end($words));
    }

    private function normalize(string $name): string
    {
        $name = preg_replace('/^(KF|FK|KS|AF)\s+/u', '', trim($name)) ?? $name;
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return strtolower(trim($transliterated !== false ? $transliterated : $name));
    }
}
