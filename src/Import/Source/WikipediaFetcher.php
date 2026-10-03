<?php
declare(strict_types=1);

namespace App\Import\Source;

use GuzzleHttp\Client;

/**
 * Downloads a Wikipedia football-season article (e.g. "2023-24 Kategoria
 * Superiore"). Plain server-rendered HTML: unlike Soccerway (whose
 * standings/home/away tables are computed client-side by JavaScript
 * against an internal GraphQL API — a plain HTTP GET never sees them) or
 * claudionicoletti.eu (an <iframe>-wrapped static export), a Wikipedia
 * article's tables are already in the HTML a simple GET returns.
 */
final class WikipediaFetcher
{
    public function __construct(private Client $http)
    {
    }

    public function fetchHtml(string $url): string
    {
        $response = $this->http->get($url, [
            'headers' => [
                'User-Agent' => 'FootballImportBot/1.0 (contact: admin@football-db.local)',
            ],
        ]);

        return (string) $response->getBody();
    }
}
