<?php
declare(strict_types=1);

/**
 * Tenký HTTP klient pro gotha-api (https://tools.daelba.eu/gotha/api).
 * Přebírá envelope {ok,status,data,meta} a mapuje chyby na ApiException.
 */
final class ApiException extends RuntimeException
{
    public const KIND_INVALID_ID = 'invalid_id';
    public const KIND_NOT_FOUND = 'not_found';
    public const KIND_UPSTREAM = 'upstream_error';

    private string $kind;

    public function __construct(string $kind, string $message = '', int $status = 0)
    {
        parent::__construct($message !== '' ? $message : $kind, $status);
        $this->kind = $kind;
    }

    public function kind(): string
    {
        return $this->kind;
    }
}

final class ApiClient
{
    private const DEFAULT_BASE = 'https://tools.daelba.eu/gotha/api/v1';
    private const TIMEOUT = 30;

    private string $base;
    private bool $force = false;

    public function __construct(?string $base = null)
    {
        // Výchozí z .env (API_BASE), fallback na vestavěnou DEFAULT_BASE.
        $base = $base !== null && $base !== '' ? $base : self::DEFAULT_BASE;
        if ( $base === self::DEFAULT_BASE && function_exists('apiBaseFromEnv') ) {
            $envBase = apiBaseFromEnv();
            if ($envBase !== null && $envBase !== '') {
                $base = $envBase;
            }
        }
        $this->base = rtrim($base, '/');
    }

    /**
     * Vynutí čerstvá data z gotha-api: ke všem dotazům přidá ?force=1,
     * čímž API přeskočí svou cache a uloží právě načtený výsledek.
     */
    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    /** @return array<int,array<string,mixed>> */
    public function families(): array
    {
        $data = $this->get('/families');
        $rows = is_array($data) ? ($data['data'] ?? null) : null;
        // kompatibilita: buď obálka {data:[…]}, nebo přímo pole
        $list = is_array($rows) ? $rows : (is_array($data) ? $data : []);
        return $list;
    }

    /** @return array<string,mixed>|null */
    public function family(string $qid): ?array
    {
        try {
            $data = $this->get('/family/' . rawurlencode($qid));
        } catch (ApiException $e) {
            if ($e->kind() === ApiException::KIND_NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        $row = is_array($data) ? ($data['data'] ?? null) : null;
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function members(string $qid): array
    {
        $data = $this->get('/family/' . rawurlencode($qid) . '/members');
        $rows = is_array($data) ? ($data['data'] ?? null) : null;
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public function entity(string $qid, string $lang = 'cs'): ?array
    {
        try {
            $data = $this->get('/entity/' . rawurlencode($qid), [
                'display' => 'full',
                'lang'    => $lang,
            ]);
        } catch (ApiException $e) {
            if ($e->kind() === ApiException::KIND_NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        $row = is_array($data) ? ($data['data'] ?? null) : null;
        return is_array($row) ? $row : null;
    }

    /**
     * Provede GET a rozbalí envelope. Při chybě vylítnuje ApiException.
     *
     * @param array<string,string> $query
     * @return array<string,mixed>  // celá envelope včetně meta
     */
    private function get(string $path, array $query = []): array
    {
        if ($this->force && !isset($query['force'])) {
            $query['force'] = '1';
        }
        $url = $this->base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&');
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Accept: application/json\r\n"
                           . "User-Agent: gotha-viewer/0.2 (+https://gotha.daelba.cz)\r\n",
                'timeout' => self::TIMEOUT,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new ApiException(ApiException::KIND_UPSTREAM, 'Gotha API nedostupné.', 502);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new ApiException(ApiException::KIND_UPSTREAM, 'Neplatná JSON odpověď z Gotha API.', 502);
        }

        $status = (int) ($json['status'] ?? 200);
        $ok = (bool) ($json['ok'] ?? false);
        if (!$ok || $status >= 400) {
            $code = (string) ($json['error_code'] ?? ApiException::KIND_UPSTREAM);
            $message = (string) ($json['error'] ?? 'Gotha API chyba.');
            $kind = \in_array($code, [
                ApiException::KIND_INVALID_ID,
                ApiException::KIND_NOT_FOUND,
                ApiException::KIND_UPSTREAM,
            ], true) ? $code : ApiException::KIND_UPSTREAM;
            throw new ApiException($kind, $message, $status);
        }

        return $json;
    }
}
