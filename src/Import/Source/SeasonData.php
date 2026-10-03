<?php
declare(strict_types=1);

namespace App\Import\Source;

/**
 * Everything parsed from one season block of a claudionicoletti.eu
 * "all final tables" decade page (e.g. ".../alb-2021-30/").
 */
final class SeasonData
{
    /**
     * @param array<int, array{position:int,name:string,played:int,won:int,drawn:int,lost:int,gf:int,ga:int,gd:int,points:int}> $standings
     * @param array<int, array{from:string,to:string}> $renames  club renames announced in this season's block
     *                                                            (the OLD name is still the one used in $standings;
     *                                                            the new name only takes effect from the next edition)
     * @param SeasonMatch[] $championshipPlayoff
     * @param SeasonMatch[] $relegationPlayoff
     * @param string[] $promoted
     * @param array<int, array{position:int,name:string,played:int,won:int,drawn:int,lost:int,gf:int,ga:int,gd:int,points:int}> $finalFour
     *                                                            same shape as $standings: a round-robin "Final Four"
     *                                                            mini-league played AFTER the regular season among
     *                                                            its top finishers, deciding the title directly
     *                                                            (distinct from $championshipPlayoff, which is a
     *                                                            KNOCKOUT bracket) — empty when the season doesn't
     *                                                            have one.
     */
    public function __construct(
        public readonly array $standings,
        public readonly array $renames,
        public readonly array $championshipPlayoff,
        public readonly array $relegationPlayoff,
        public readonly array $promoted,
        public readonly array $finalFour = [],
    ) {
    }
}
