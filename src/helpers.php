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
