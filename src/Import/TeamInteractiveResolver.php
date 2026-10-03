<?php
declare(strict_types=1);

namespace App\Import;

/**
 * When a scraped team name can't be resolved automatically, asks a human
 * at the terminal whether it's a brand new club or a rename of an existing
 * one, instead of just stopping the import.
 *
 * Only active when STDIN is an interactive TTY. In any non-interactive
 * context (cron, piped input, CI), or once standard input is exhausted
 * mid-run, resolve() returns null immediately so the caller falls back to
 * its original, safe stop-and-report behavior.
 */
final class TeamInteractiveResolver
{
    private bool $interactive;

    public function __construct(private TeamResolver $teams)
    {
        $this->interactive = function_exists('posix_isatty') ? (bool) @posix_isatty(STDIN) : true;
    }

    /**
     * @return array{team_name_id:int,team_id:int,name:string,fuzzy:bool,renamed_from:?string}|null
     *         null means: give up, fall back to the non-interactive behavior
     *         (stop and report this name as unresolved).
     */
    public function resolve(UnresolvedTeamException $exception, int $federationTeamId, string $language, string $country): ?array
    {
        if (!$this->interactive) {
            return null;
        }

        echo "\n" . $exception->getMessage() . "\n";
        echo sprintf("\"%s\" n'a pas pu être rapproché automatiquement. Comment le traiter ?\n", $exception->scrapedName);
        echo "  [n] Nouvelle équipe (jamais vue dans la base)\n";

        $allowed = ['n', 's'];
        if ($exception->candidates !== []) {
            echo "  [r] Renommage d'une équipe existante (un des candidats ci-dessus)\n";
            $allowed[] = 'r';
        }
        echo "  [s] Ignorer — laisser non résolu (l'import s'arrêtera avant d'écrire quoi que ce soit)\n";

        $choice = $this->promptChoice('Choix [' . implode('/', $allowed) . ']: ', $allowed);

        if ($choice === null || $choice === 's') {
            return null;
        }

        return $choice === 'n'
            ? $this->createNewTeam($exception->scrapedName, $federationTeamId, $language, $country)
            : $this->renameExisting($exception, $federationTeamId, $language, $country);
    }

    /**
     * @return array{team_name_id:int,team_id:int,name:string,fuzzy:bool,renamed_from:?string}|null
     */
    private function createNewTeam(string $scrapedName, int $federationTeamId, string $language, string $country): ?array
    {
        $name = $this->promptRaw(sprintf(
            'Nom à enregistrer en base (Entrée pour garder "%s") : ',
            $scrapedName
        ));

        if ($name === null) {
            return null;
        }

        if ($name === '') {
            $name = $scrapedName;
        }

        $officialName = $this->promptRaw('Nom officiel (official_name, optionnel, Entrée pour laisser vide) : ');
        if ($officialName === null) {
            return null;
        }
        $officialName = $officialName === '' ? null : $officialName;

        $fullName = $this->promptRaw('Nom complet (full_name, optionnel, Entrée pour laisser vide) : ');
        if ($fullName === null) {
            return null;
        }
        $fullName = $fullName === '' ? null : $fullName;

        $geonameId = null;

        while ($geonameId === null) {
            $city = $this->promptRaw(sprintf('Ville de "%s" (pour géolocalisation) : ', $name));
            if ($city === null) {
                return null;
            }

            $geonameId = $this->teams->findGeonameId($city);
            if ($geonameId !== null) {
                break;
            }

            echo sprintf("  (!) Aucune ville \"%s\" trouvée dans la table geoname.\n", $city);

            $retry = $this->promptChoice('Réessayer avec un autre nom ? [o/n] ', ['o', 'n']);
            if ($retry === null) {
                return null;
            }
            if ($retry === 'n') {
                $manual = $this->promptRaw('ID geoname à utiliser manuellement : ');
                if ($manual === null || $manual === '') {
                    return null;
                }
                $geonameId = (int) $manual;
                break;
            }
        }

        $created = $this->teams->createTeam($name, $federationTeamId, $language, $country, $geonameId, $officialName, $fullName);

        return $created + ['renamed_from' => null];
    }

    /**
     * @return array{team_name_id:int,team_id:int,name:string,fuzzy:bool,renamed_from:?string}|null
     */
    private function renameExisting(UnresolvedTeamException $exception, int $federationTeamId, string $language, string $country): ?array
    {
        foreach ($exception->candidates as $i => $candidate) {
            echo sprintf("  [%d] %s (team_name_id=%d, distance=%d)\n", $i, $candidate['name'], $candidate['team_name_id'], $candidate['distance']);
        }

        $raw = $this->promptRaw('Numéro du candidat à renommer (vide pour annuler) : ');
        if ($raw === null || $raw === '') {
            return null;
        }

        $chosen = $exception->candidates[(int) $raw] ?? null;
        if ($chosen === null) {
            echo "  (!) Choix invalide.\n";

            return $this->renameExisting($exception, $federationTeamId, $language, $country);
        }

        $newTeamNameId = $this->teams->applyRename($chosen['name'], $exception->scrapedName, $federationTeamId, $language, $country);

        return [
            'team_name_id' => $newTeamNameId,
            'team_id' => $chosen['team_id'],
            'name' => $exception->scrapedName,
            'fuzzy' => false,
            'renamed_from' => $chosen['name'],
        ];
    }

    private function promptChoice(string $label, array $allowed): ?string
    {
        while (true) {
            $answer = $this->promptRaw($label);
            if ($answer === null) {
                return null;
            }

            $answer = strtolower($answer);
            if (in_array($answer, $allowed, true)) {
                return $answer;
            }

            echo sprintf("  (!) Réponse invalide, attendu : %s\n", implode('/', $allowed));
        }
    }

    private function promptRaw(string $label): ?string
    {
        echo $label;
        $line = fgets(STDIN);

        if ($line === false) {
            $this->interactive = false;
            echo "\n  (!) Entrée standard fermée — passage en mode non interactif pour la suite.\n";

            return null;
        }

        return trim($line);
    }
}
