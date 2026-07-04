<?php
namespace App\Elastic\Indexer;

use App\Elastic\ElasticClient;
use App\Elastic\Indexer\AbstractIndexer;
use App\Query\SqlQueryHandler;

class StandingIndexer extends AbstractIndexer
{
    private $teams = [], $corrections = [], $statuses = [];

    public function __construct(
        private ElasticClient $client,
        private SqlQueryHandler $handler
    ) {
        parent::__construct($client,$handler);
    }

    public function getIndexQuery(?int $id = null): string 
    {
        $this->preBuildTeams($id);
        $this->preBuildCorrections($id);
        $this->preBuildStatus($id);

        return <<<SQL
SELECT
    s.id,
    s.teams_number,
    s.position,
    s.stage_id,
    s.edition_id,
    s.w, s.d, s.l, s.gf, s.ga,
    s.pts,
    s.hw, s.hd, s.hl, s.hgf, s.hga,
    s.aw, s.ad, s.al, s.agf, s.aga,
    s.wp, s.lp, s.hwp, s.hlp, s.awp, s.alp,
    s.is_alltime,
    s.is_reference,
    s.team_name_id
FROM standing s
LEFT JOIN standing_status ss ON ss.standing_id = s.id
LEFT JOIN status sta ON sta.id = ss.status_id
GROUP BY s.id
SQL;
    }

    private function preBuildTeams(?int $id): void
    {
        if ($id) {
          $sql = sprintf('SELECT tn.* FROM team_name tn LEFT JOIN standing s ON s.team_name_id = tn.id WHERE s.id = %d', $id);
        } else {
          $sql = 'SELECT * FROM team_name';
        }
        foreach($this->handler->iterator($sql) as $row) {
            $this->teams[$row['id']] = $row;
        }
    }

    private function preBuildCorrections(?int $id): void
    {
        $sql = sprintf('SELECT * FROM correction %s', $id ? sprintf(' WHERE standing_id = %d', $id) : '');
        foreach($this->handler->iterator($sql) as $row) {
            $this->corrections[$row['standing_id']][] = $row;
        }
    }

    private function preBuildStatus(?int $id): void
    {
        $sql = sprintf('SELECT ss.id, ss.standing_id, ss.status_id, s.code, s.name
            FROM standing_status ss LEFT JOIN status s ON s.id = ss.status_id %s', $id ? sprintf(' WHERE ss.standing_id = %d', $id) : '');
        foreach($this->handler->iterator($sql) as $row) {
            $this->statuses[$row['standing_id']][] = $row;
        }
    }

    public function buildDocument(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'teams_number' => (int)$row['teams_number'],
            'position' => (int)$row['position'],
            'stage_id' => (int)$row['stage_id'],
            'edition_id' => (int)$row['edition_id'],
            'w' => (int)$row['w'], 'd' => (int)$row['d'], 'l' => (int)$row['l'], 'gf' => (int)$row['gf'], 'ga' => (int)$row['ga'],
            'pts' => (float)$row['pts'],
            'hw' => (int)$row['hw'], 'hd' => (int)$row['hd'], 'hl' => (int)$row['hl'], 'hgf' => (int)$row['hgf'], 'hga' => (int)$row['hga'],
            'aw' => (int)$row['aw'], 'ad' => (int)$row['ad'], 'al' => (int)$row['al'], 'agf' => (int)$row['agf'], 'aga' => (int)$row['aga'],
            'wp' => (int)$row['wp'], 'lp' => (int)$row['lp'], 'hwp' => (int)$row['hwp'], 'hlp' => (int)$row['hlp'], 'awp' => (int)$row['awp'], 'alp' => (int)$row['alp'],
            'is_alltime' => (bool)$row['is_alltime'],
            'is_reference' => (bool)$row['is_reference'],
            'team_name_id' => (int)$row['team_name_id'],
            'team' => $this->teams[$row['team_name_id']] ?? [],
            'corrections' => $this->corrections[$row['id']] ?? [],
            'status' => $this->statuses[$row['id']] ?? []
        ];
    }
}