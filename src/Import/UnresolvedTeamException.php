<?php
declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Thrown when a team name scraped from a source cannot be matched to an
 * existing `team_name` row with enough confidence, and is not the subject
 * of a rename announced in the same source. The import must stop rather
 * than guess: creating a `team`/`team_name` row is a decision left to a
 * human (see README "Nouvelles équipes").
 */
final class UnresolvedTeamException extends RuntimeException
{
    /**
     * @param array<int, array{name:string,team_name_id:int,distance:int}> $candidates
     */
    public function __construct(
        public readonly string $scrapedName,
        public readonly array $candidates = [],
    ) {
        $hint = $candidates === []
            ? 'no close match found'
            : sprintf(
                'closest matches: %s',
                implode(', ', array_map(
                    static fn (array $c) => sprintf('"%s" (id=%d, distance=%d)', $c['name'], $c['team_name_id'], $c['distance']),
                    $candidates
                ))
            );

        parent::__construct(sprintf('Unresolved team "%s": %s', $scrapedName, $hint));
    }
}
