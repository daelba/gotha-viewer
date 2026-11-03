<?php
declare(strict_types=1);

// Načtení konfigurace z .env (doména, cesta)
$envFile = dirname(__DIR__) . '/.env';
$appPath = '';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (trim($key) === 'APP_PATH') {
            $appPath = rtrim(trim($value), '/');
        }
    }
}
// APP_PATH jako konstanta pro celou aplikaci
if (!defined('APP_PATH')) {
    define('APP_PATH', $appPath);
}

require dirname(__DIR__) . '/src/bootstrap.php';

I18n::init();

$api = new ApiClient(apiBaseFromEnv());
// ?force=1 v URL → předat do gotha-api (přeskočí tamní cache a data obnoví)
if (isset($_GET['force'])) {
    $api->setForce(true);
}
$repo = new FamilyRepository($api);

// --- RESTful URL mapování ---
// /                  → seznam rodů
// /family/{QID}      → detail + strom rodu
// /api/person/{QID}  → JSON detail osoby (AJAX)
// /refresh           → obnovit data (přeskočit cache v gotha-api) + návrat na Referer

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
// Odstraň prefix aplikace (např. /html/gotha-viewer/public) z URL pro router
if (APP_PATH !== '' && strpos($path, ltrim(APP_PATH, '/')) === 0) {
    $path = ltrim(substr($path, strlen(APP_PATH)), '/');
}
$segs = $path === '' ? [] : explode('/', $path);

// Jednotný zdroj routingu: preferuj $_GET (query-string rewrite z .htaccess),
// fallback na segmenty z REQUEST_URI (vestavěný PHP server / index.php path).
$route = $segs;
if (isset($_GET['refresh']) || isset($_GET['family']) || isset($_GET['api'])) {
    $route = [];
    if (isset($_GET['refresh'])) {
        $route = ['refresh'];
    } elseif (($_GET['api'] ?? '') !== '') {
        $route = ['api', (string) ($_GET['api'] ?? ''), (string) ($_GET['id'] ?? '')];
    } elseif (isset($_GET['family'])) {
        $route = ['family', (string) $_GET['family']];
    }
}

// RESTful akce
if ($route && $route[0] === 'refresh') {
    // Návratová URL: preferuj explicitní ?back= z vykreslené stránky
    // (Referer prohlížeč nemusí poslat – proxy nasazuje Referrer-Policy:
    // no-referrer), jako fallback zkus Referer, jinak úvodní stránku.
    // back musí být lokální cesta od kořene („/", ne „//") – prevence open-redirect.
    $back = $_GET['back'] ?? '';
    if (!is_string($back) || preg_match('#^/(?!/)#', $back) !== 1) {
        $back = $_SERVER['HTTP_REFERER'] ?? (APP_PATH . '/');
    }
    // ?force=1 na návratové URL: při načtení stránky ho viewer předá dál do API
    $url = preg_match('/^[?&]/', $back) === 1
        ? $back . '&force=1'
        : $back . (parse_url($back, PHP_URL_QUERY) ? '&' : '?') . 'force=1';
    header('Location: ' . $url);
    exit;
}

if ($route && $route[0] === 'api' && ($route[1] ?? '') === 'person') {
    $_GET['id'] = $route[2] ?? '';
    ApiController::person();
    exit;
}

if ($route && $route[0] === 'family' && isset($route[1])) {
    $_GET['family'] = $route[1];
}

// --- Stránky ---
$familyId = $_GET['family'] ?? '';

if (is_string($familyId) && preg_match('/^Q[1-9][0-9]*$/', $familyId) === 1) {
    $family = $repo->family($familyId);
    if ($family === null) {
        http_response_code(404);
        $content = '<p class="flash flash-error">' . e(t('error.404')) . '</p>';
    } else {
        $members = $repo->members($familyId) ?? [];
        $hasMembers = count($members) > 0;
        $layout = $hasMembers ? TreeBuilder::layout($members) : ['families' => [], 'people' => [], 'order' => [], 'dups' => [], 'couples' => []];

        $content = View::render('family', [
            'family'     => $family,
            'members'    => $members,
            'tree'       => $layout,
            'edges'      => $hasMembers ? TreeBuilder::edges($layout['families'], $layout['people'], $layout['dups'], $layout['couples']) : [],

            'cellW'      => TreeBuilder::CELL_W,
            'cellH'      => TreeBuilder::CELL_H,
            'hasMembers' => $hasMembers,
        ]);
    }
    echo View::render('layout', [
        'content' => $content,
        'title'   => I18n::pick($family['labels'] ?? ['cs' => null, 'de' => null, 'en' => null]),
    ]);
    exit;
}

// výchozí: seznam rodů
$families = $repo->families();
if ($families === null) {
    $content = '<p class="flash flash-error">' . e(t('error.endpoint')) . '</p>';
} else {
    $content = View::render('families', ['families' => $families]);
}
echo View::render('layout', ['content' => $content, 'title' => null]);
