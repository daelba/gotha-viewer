<?php
declare(strict_types=1);

/**
 * Miniaturní šablonovací systém: PHP soubor + $data.
 */
final class View
{
    /**
     * Vykreslí šablonu.
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        $file = TEMPLATES_DIR . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Šablona '$template' neexistuje (hledáno v $file).");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
