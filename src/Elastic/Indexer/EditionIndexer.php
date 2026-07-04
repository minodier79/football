<?php
namespace App\Elastic\Indexer;

use App\Elastic\ElasticClient;
use App\Elastic\Indexer\AbstractIndexer;
use App\Query\SqlQueryHandler;

class EditionIndexer extends AbstractIndexer
{
    public function __construct(
        private ElasticClient $client,
        private SqlQueryHandler $handler
    ) {
        parent::__construct($client,$handler);
    }

    public function getIndexQuery(?int $id = null): string 
    {
        return sprintf(<<<SQL
SELECT
    e.id,
    e.name,
    e.sequence,
    e.is_current,
    GROUP_CONCAT(es.comment), 
    GROUP_CONCAT(esl.name) AS status_label,
    t.id AS tournament_id,
    t.name AS tournament_name,
    COALESCE(
        JSON_ARRAYAGG(
            JSON_OBJECT(
                'id', tn.id,
                'name', tn.name
            )
        ),
        JSON_ARRAY()
    ) AS winner,
    COALESCE(
        JSON_ARRAYAGG(
            JSON_OBJECT(
                'id', s.id,
                'sequence', s.sequence,
                'hierarchy', s.hierarchy,
                'name', s.name,
                'is_playoff', s.is_playoff,
                'is_reference', s.is_reference
            )
        ),
        JSON_ARRAY()
    ) AS stages
FROM edition e
LEFT JOIN edition_status es ON es.edition_id = e.id
LEFT JOIN edition_status_label esl ON esl.id = es.status_id
LEFT JOIN tournament t ON t.id = e.tournament_id
LEFT JOIN template tp ON tp.id = e.template_id
LEFT JOIN stage s ON s.template_id = tp.id
LEFT JOIN winner w ON w.edition_id = e.id
LEFT JOIN team_name tn ON tn.id = w.team_name_id
%s
GROUP BY e.id
SQL, $id ? sprintf('WHERE e.id = %d', $id) : '');
    }

    public function buildDocument(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'sequence' => (int)$row['sequence'],
            'status_label' => $row['status_label'],
            'tournament' => [
                'id' => (int)$row['tournament_id'],
                'name' => $row['tournament_name']
            ],
            'stages' => json_decode(
                $row['stages'] ?? '[]',
                true
            ),
            'winner' => json_decode(
                $row['winner'] ?? '[]',
                true
            ),
            'is_current' => (bool)$row['is_current']
        ];
    }
}