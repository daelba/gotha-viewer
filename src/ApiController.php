<?php
declare(strict_types=1);

/**
 * AJAX API: detail entity (osoba / rod) pro postranní panel.
 * GET /api/person.php?id=Q647[&force]
 * Vrací JSON: { id, name, wikiUrl, wikidataId, claims: [{pid,plabel,values:[{value,link?}],join?}] }
 *
 * Řazení, skrytí a formátování řádků řídí šablona config/panel-fields.json
 * (PanelTemplate); chybí-li nebo je nevalidní, claims projdou nezpracované.
 * Data čerpá výhradně z gotha-api (ApiClient) – bez SPARQL a bez lokální cache.
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

        $client = new ApiClient(apiBaseFromEnv());
        if (isset($_GET['force'])) {
            $client->setForce(true);
        }
        $repo   = new FamilyRepository($client);
        $person = $repo->entity($id);

        if ($person === null) {
            http_response_code(404);
            echo json_encode(['error' => 'person not found']);
            return;
        }

        $template = PanelTemplate::load(ROOT_DIR . '/config/panel-fields.json');

        // Výstupní řádky dle šablony; bez ní fallback = původní pořadí z API.
        $claimsOut = [];
        if ($template !== null) {
            $claimsOut = $template->apply($person['claims']);
        } else {
            foreach ($person['claims'] as $c) {
                $claimsOut[] = [
                    'pid'    => $c['pid'],
                    'plabel' => $c['plabel'],
                    'values' => [['value' => $c['value'], 'link' => $c['link']]],
                ];
            }
        }

        echo json_encode([
            'id'          => $id,
            'name'        => $person['label'] !== '' ? $person['label'] : $id,
            'wikiUrl'     => $person['wiki_url'],
            'wikidataId'  => $person['wikidataId'],
            'claims'      => $claimsOut,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
