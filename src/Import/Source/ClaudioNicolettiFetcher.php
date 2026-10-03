<?php
declare(strict_types=1);

namespace App\Import\Source;

use GuzzleHttp\Client;

/**
 * Downloads a claudionicoletti.eu "all final tables" page and returns the
 * underlying HTML table as a UTF-8 string.
 *
 * The public WordPress page (e.g. .../albania-league-final-tables/alb-2021-30/)
 * only embeds the real data in an <iframe> pointing at a static
 * .../tabelle/Euroleagues/<CODE>/<name>.htm export (one big <table>, all
 * seasons of the decade concatenated as <tr> rows, in the encoding declared
 * by its own <meta charset>). This class follows that iframe automatically,
 * so callers can pass either URL.
 */
final class ClaudioNicolettiFetcher
{
    public function __construct(private Client $http)
    {
    }

    public function fetchHtml(string $url): string
    {
        $body = $this->get($url);

        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
            $iframeUrl = $this->resolveUrl($url, html_entity_decode($m[1]));
            $body = $this->get($iframeUrl);
        }

        return $this->toUtf8($body);
    }

    private function get(string $url): string
    {
        $response = $this->http->get($url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; FootballImportBot/1.0)',
            ],
        ]);

        return (string) $response->getBody();
    }

    private function resolveUrl(string $baseUrl, string $target): string
    {
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($target, '/')) {
            return sprintf('%s://%s%s', $scheme, $host, $target);
        }

        $basePath = rtrim(dirname($base['path'] ?? '/'), '/');

        return sprintf('%s://%s%s/%s', $scheme, $host, $basePath, $target);
    }

    private function toUtf8(string $html): string
    {
        $charset = 'Windows-1252';

        if (preg_match('/charset=([\w-]+)/i', $html, $m)) {
            $charset = $m[1];
        }

        if (strcasecmp($charset, 'UTF-8') === 0) {
            return $html;
        }

        $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $html);

        if ($converted === false) {
            return $html;
        }

        // The document's own <meta charset=...>/<meta http-equiv=Content-Type
        // content="...charset=...">  tag still says the ORIGINAL charset at
        // this point (iconv only re-encodes the bytes, it doesn't touch that
        // text). DOMDocument::loadHTML() sniffs that tag independently of
        // the "<?xml encoding="UTF-8">" prefix trick ClaudioNicolettiParser
        // uses to force UTF-8 — and can win, silently re-decoding our
        // already-correct UTF-8 bytes as the stale charset a second time
        // (e.g. "Rrogozhinë" -> "RrogozhinÃ«"). Rewrite the declaration to
        // match what we actually produced so both ways of telling
        // DOMDocument the encoding agree.
        return preg_replace('/charset=[\w-]+/i', 'charset=UTF-8', $converted, 1) ?? $converted;
    }
}
