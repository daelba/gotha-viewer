<?php
declare(strict_types=1);

/**
 * AJAX API: detail osoby pro postranní panel.
 * GET /api/person.php?id=Q647[&force]
 * Vrací JSON: { id, name, claims: [{plabel, value, vlabel, link?}] }
 */
final class ApiController
{
    public static function person(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $id = $_GET['id'] ?? '';
        if (!is_string($id) || preg_match('/^Q[1-9][0-9]*$/', $id) !== 1) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            return;
        }

        // volitelné vynucení nového načtení (pro aktualizaci cache jedné osoby)
        if (isset($_GET['force'])) {
            // přeskočíme cache jen pro tento request
            $client = new SparqlClient(new FileCache(CACHE_DIR . '/.tmp'));
        } else {
            $client = new SparqlClient(new FileCache(CACHE_DIR));
        }
        $repo = new FamilyRepository($client);
        $person = $repo->person($id);

        if ($person === null) {
            http_response_code(404);
            echo json_encode(['error' => 'person not found']);
            return;
        }

        $name = I18n::pick($person['labels']) ?: $id;

        $claimsOut = [];
        foreach ($person['claims'] as $c) {
            $link = null;
            if ($c['pid'] === 'P10' && $c['type'] === 'literal') {
                $link = 'https://www.wikidata.org/wiki/' . rawurlencode($c['value']);
            }
            $claimsOut[] = [
                'pid'    => $c['pid'],
                'plabel' => $c['plabel'],
                'value'  => $c['vlabel'] ?? $c['value'],
                'link'   => $link,
            ];
        }

        echo json_encode([
            'id'     => $id,
            'name'   => $name,
            'claims' => $claimsOut,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
