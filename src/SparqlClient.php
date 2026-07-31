<?php
declare(strict_types=1);

/**
 * Tenký SPARQL klient pro gotha.wikibase.cloud.
 * POST dotaz → JSON výsledek, s file cache (TTL 24 h).
 */
final class SparqlClient
{
    public const PREFIXES = <<<SPARQL
PREFIX gd: <https://gotha.wikibase.cloud/entity/>
PREFIX gds: <https://gotha.wikibase.cloud/entity/statement/>
PREFIX gdv: <https://gotha.wikibase.cloud/value/>
PREFIX gdt: <https://gotha.wikibase.cloud/prop/direct/>
PREFIX gp: <https://gotha.wikibase.cloud/prop/>
PREFIX gps: <https://gotha.wikibase.cloud/prop/statement/>
PREFIX gpq: <https://gotha.wikibase.cloud/prop/qualifier/>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SPARQL;

    private const ENDPOINT = 'https://gotha.wikibase.cloud/query/sparql';
    private const TTL = 86400; // 24 hodin
    private const TIMEOUT = 30;

    private FileCache $cache;

    public function __construct(FileCache $cache)
    {
        $this->cache = $cache;
    }

    public function cache(): FileCache
    {
        return $this->cache;
    }

    /**
     * Provede SELECT dotaz a vrátí pole bindings (nebo null při chybě).
     * @return array<int,array<string,array{type:string,value:string}>>|null
     */
    public function query(string $sparql): ?array
    {
        $sparql = self::PREFIXES . "\n" . $sparql;
        $key = $sparql;

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $json = $this->post($sparql);
        if ($json === null) {
            return null;
        }
        $decoded = json_decode($json, true);
        $bindings = $decoded['results']['bindings'] ?? null;
        if (!is_array($bindings)) {
            return null;
        }

        $this->cache->set($key, $bindings, self::TTL);
        return $bindings;
    }

    private function post(string $sparql): ?string
    {
        // Submission: URL-encoded POST dle SPARQL 1.1 protokolu
        $body = http_build_query(['query' => $sparql], '', '&');
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Accept: application/sparql-results+json\r\n"
                           . "Content-Type: application/x-www-form-urlencoded\r\n"
                           . "User-Agent: gotha-viewer/0.1 (+https://gotha.daelba.cz)\r\n",
                'content' => $body,
                'timeout' => self::TIMEOUT,
            ],
        ]);
        $result = @file_get_contents(self::ENDPOINT, false, $ctx);
        return $result === false ? null : $result;
    }
}
