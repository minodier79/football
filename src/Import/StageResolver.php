<?php
declare(strict_types=1);

namespace App\Import;

use App\Import\Source\SeasonData;
use App\Query\SqlQueryHandler;
use PDO;

/**
 * Finds (or creates) the `template`/`stage` rows an edition needs.
 *
 * Mirrors, in code, the manual process used the first time this database
 * gained a "championship play-off" format: look for an EXISTING template
 * whose stage set matches exactly what this season needs; only create a
 * brand new, dedicated template when nothing matches — never repurpose a
 * template shared by unrelated tournaments/editions (see project notes:
 * template_id=21 was rejected for exactly that reason).
 */
final class StageResolver
{
    private const HIERARCHY = [
        'Final Table' => 1,
        'Final Four' => 1,
        'Promotion/relegation play-off' => 2,
        'Semifinals' => 3,
        'Third place' => 3,
        'Final' => 3,
    ];

    public function __construct(
        private PDO $pdo,
        private SqlQueryHandler $db,
    ) {
    }

    /**
     * @return string[] stage names, in the order they should get their
     *                   sequence number
     */
    public function requiredStages(SeasonData $season): array
    {
        $stages = ['Final Table'];

        if ($season->finalFour !== []) {
            $stages[] = 'Final Four';
        }

        if ($season->relegationPlayoff !== []) {
            $stages[] = 'Promotion/relegation play-off';
        }

        if ($season->championshipPlayoff !== []) {
            $hasThirdPlace = false;
            foreach ($season->championshipPlayoff as $match) {
                if ($match->stage === '3rd place final') {
                    $hasThirdPlace = true;
                }
            }

            $stages[] = 'Semifinals';
            if ($hasThirdPlace) {
                $stages[] = 'Third place';
            }
            $stages[] = 'Final';
        }

        return $stages;
    }

    /**
     * @param string[] $requiredStages
     *
     * @return array{template_id:int, stages: array<string,int>}
     */
    public function resolve(array $requiredStages, int $tournamentId): array
    {
        $existing = $this->findMatchingTemplate($requiredStages);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createTemplate($requiredStages, $tournamentId);
    }

    /**
     * @param string[] $requiredStages
     *
     * @return array{template_id:int, stages: array<string,int>}|null
     */
    private function findMatchingTemplate(array $requiredStages): ?array
    {
        $placeholders = implode(',', array_fill(0, count($requiredStages), '?'));

        // Templates that contain every required name AND have exactly that
        // many stages (so nothing extra like an unrelated "Group A" sneaks in).
        $sql = "SELECT template_id
                FROM stage
                WHERE name IN ($placeholders)
                GROUP BY template_id
                HAVING COUNT(DISTINCT name) = ?
                   AND COUNT(*) = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$requiredStages, count($requiredStages), count($requiredStages)]);

        $templateId = $stmt->fetchColumn();

        if ($templateId === false) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name FROM stage WHERE template_id = ?');
        $stmt->execute([$templateId]);

        $stages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stages[$row['name']] = (int) $row['id'];
        }

        return ['template_id' => (int) $templateId, 'stages' => $stages];
    }

    /**
     * @param string[] $requiredStages
     *
     * @return array{template_id:int, stages: array<string,int>}
     */
    private function createTemplate(array $requiredStages, int $tournamentId): array
    {
        $slug = $this->slugFor($requiredStages);

        $templateId = $this->db->insert('template', ['name' => $slug]);

        $stages = [];
        foreach (array_values($requiredStages) as $i => $name) {
            $stages[$name] = $this->db->insert('stage', [
                'sequence' => $i + 1,
                'hierarchy' => self::HIERARCHY[$name] ?? 1,
                'name' => $name,
                'is_playoff' => in_array($name, ['Final Table', 'Final Four'], true) ? 0 : 1,
                'template_id' => $templateId,
            ]);
        }

        return ['template_id' => $templateId, 'stages' => $stages];
    }

    /**
     * @param string[] $requiredStages
     */
    private function slugFor(array $requiredStages): string
    {
        $tokens = [
            'Final Table' => 'ft',
            'Final Four' => 'ff',
            'Promotion/relegation play-off' => 'prpo',
            'Semifinals' => '1/2',
            'Third place' => '3rd',
            'Final' => 'f',
        ];

        return implode('_', array_map(
            static fn (string $name) => $tokens[$name] ?? strtolower($name),
            $requiredStages
        ));
    }
}
