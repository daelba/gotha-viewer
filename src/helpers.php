<?php
declare(strict_types=1);

/** HTML escape helper. */
function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Překladový helper (zkratka pro I18n::t). */
function t(string $key): string
{
    return I18n::t($key);
}

/** Základ API z .env (klíč API_BASE), bez koncového lomítka. */
function apiBaseFromEnv(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = dirname(__DIR__) . '/.env';
    if (is_readable($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'API_BASE') {
                $cached = rtrim(trim($v), '/');
                return $cached;
            }
        }
    }
    $cached = null;
    return $cached;
}
