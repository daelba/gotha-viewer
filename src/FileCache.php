<?php
declare(strict_types=1);

/**
 * Souborová cache pro SPARQL výsledky.
 * Klíč = md5(prefix dotazu), hodnota = serializované pole + expirace.
 */
final class FileCache
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key)
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !isset($payload['expires'], $payload['data'])) {
            return null;
        }
        if ($payload['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $payload['data'];
    }

    public function set(string $key, $data, int $ttlSeconds): void
    {
        $payload = [
            'expires' => time() + $ttlSeconds,
            'data'    => $data,
        ];
        // Atomic write: nejdřív dočasný soubor, pak rename.
        $tmp = tempnam($this->dir, 'tmp');
        file_put_contents($tmp, serialize($payload), LOCK_EX);
        rename($tmp, $this->path($key));
    }

    /**
     * Smaže celou cache (všechny *.cache soubory).
     * @return int počet smazaných souborů
     */
    public function clear(): int
    {
        $count = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }
}
