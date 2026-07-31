<?php
declare(strict_types=1);

/**
 * Vlastní mini autoloader (bez Composeru).
 * Hledá třídy v src/ podle názvu souboru (PSR-4-like).
 */
spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . '/';
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';

// Cesty
define('ROOT_DIR', dirname(__DIR__));
define('CACHE_DIR', ROOT_DIR . '/cache');
define('TEMPLATES_DIR', ROOT_DIR . '/templates');

// Výchozí časová zóna
date_default_timezone_set('Europe/Prague');
