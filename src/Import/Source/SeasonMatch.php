<?php
declare(strict_types=1);

namespace App\Import\Source;

/**
 * One match parsed from a claudionicoletti.eu season block
 * (championship play-off or promotion/relegation play-off).
 */
final class SeasonMatch
{
    public function __construct(
        public readonly string $stage,   // e.g. 'Semifinals', '3rd place final', 'Final', 'Quarter-finals'
        public readonly string $home,
        public readonly string $away,
        public readonly ?int $homeGoals,
        public readonly ?int $awayGoals,
        public readonly bool $aet = false,
        public readonly ?int $homePenalties = null,
        public readonly ?int $awayPenalties = null,
        public readonly ?string $venue = null,
        public readonly ?string $date = null, // Y-m-d
        public readonly ?string $note = null,
    ) {
    }

    /**
     * 1 = home win, 2 = away win, null = draw (incl. draw decided on penalties,
     * since the DB schema has no dedicated penalty-winner column beyond team_winner).
     */
    public function winner(): ?int
    {
        if ($this->homePenalties !== null && $this->awayPenalties !== null) {
            return $this->homePenalties > $this->awayPenalties ? 1 : 2;
        }

        if ($this->homeGoals === null || $this->awayGoals === null || $this->homeGoals === $this->awayGoals) {
            return null;
        }

        return $this->homeGoals > $this->awayGoals ? 1 : 2;
    }
}
