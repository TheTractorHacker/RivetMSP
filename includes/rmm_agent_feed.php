<?php

/*
 * Agent updates: the public release feed of the endpoint agent (GitHub releases of rivet-core, tags agent-v*) and the one-click "Publish" that puts a release
 * into this installation. Administration > Endpoint agent > Agent updates (admin/settings_endpoint_agent.php, admin/post/settings_endpoint_agent.php) is the only user.
 *
 * What this file is, and what it is not:
 *   - The edition's code (RivetCore stays untouched). It READS the GitHub releases API, DOWNLOADS the assets, VERIFIES their SHA-256 against the release's own
 *     SHA256SUMS / SHA256SUMS.msi, and only then hands the verified file to the code that already exists for the upload form and scripts/endpoint_agent_publish.php:
 *     RmmAdmin::uploadBinary() (BinaryStore::publish: PE/architecture check, size cap, immutability, optional release row) and RmmAdmin::uploadMsiTemplate()
 *     (BinaryStore::publishMsi). Those do the authorization and the audit rows; nothing here writes the binary tables.
 *   - Linux assets (install-linux.sh, the tarballs, .deb, .rpm) are NOT hosted here (BinaryStore refuses an ELF on purpose). They are RECORDED as links to the release,
 *     with the SHA-256 the release publishes, so the "Build installer" page can hand out a self-verifying Linux script and package links.
 *
 * SSRF: the only hosts ever contacted are GitHub's (api.github.com, github.com and the release-asset CDN hosts), over https on port 443, with no credentials in the URL
 * and no redirect to anywhere else. A host must resolve to public addresses only (the connection is pinned to the checked address). Every download has a size cap
 * and a time limit. The URLs are BUILT here from the validated repository, tag and a fixed list of asset names; a URL from the API response is never followed.
 * The single exception is an install-level constant for the automated tests (RIVET_AGENT_FEED_TEST_ORIGIN in config.php, loopback only), which is never read from the
 * database, a request or the environment by this file.
 */

namespace RivetMsp\AgentFeed;

/** A small, SSRF-safe https client for the GitHub release feed. */
final class FeedHttp
{
    /** The hosts a release can come from: the API, the web host that serves /releases/download/, and GitHub's release-asset CDN hosts. */
    public const HOSTS = ['api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com'];
    public const MAX_REDIRECTS = 4;

    /** @param string|null $testOrigin "http://127.0.0.1:PORT" of a local mock (tests only; comes from a config.php constant) */
    public function __construct(private readonly ?string $testOrigin = null)
    {
    }

    /** Whether the URL may be contacted at all (scheme, host list, port, no userinfo, no IP literal). */
    public function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $host = strtolower((string) $p['host']);
        if ($this->testOrigin !== null) {
            $t = parse_url($this->testOrigin);
            if (is_array($t) && strtolower((string) $p['scheme']) === ($t['scheme'] ?? '') && $host === strtolower((string) ($t['host'] ?? '')) && (int) ($p['port'] ?? 0) === (int) ($t['port'] ?? -1)) {
                return true;
            }
        }
        if (strtolower((string) $p['scheme']) !== 'https' || !in_array($host, self::HOSTS, true)) {
            return false;
        }

        return !isset($p['port']) || (int) $p['port'] === 443;
    }

    /**
     * GET a URL, following at most MAX_REDIRECTS redirects by hand (each hop is checked again). With $toFile the body is streamed to that file instead of memory.
     *
     * @param list<string> $headers extra request headers
     * @return array{ok:bool,status:int,body:string,error:string,headers:array<string,string>,bytes:int,rate_limited:bool,reset:?int,host:string}
     */
    public function get(string $url, int $maxBytes, int $timeoutS, array $headers = [], ?string $toFile = null, ?string $postBody = null): array
    {
        // 26.10.31: a request that carries an Authorization header (the build token) goes to api.github.com ONLY and is never redirected: the token must not follow
        // a Location to the download hosts. With $postBody the request is a POST (the workflow dispatch).
        $authed = false;
        foreach ($headers as $hd) {
            if (stripos(ltrim($hd), 'authorization:') === 0) {
                $authed = true;
            }
        }
        $res = ['ok' => false, 'status' => 0, 'body' => '', 'error' => '', 'headers' => [], 'bytes' => 0, 'rate_limited' => false, 'reset' => null, 'host' => ''];
        if (!function_exists('curl_init')) {
            $res['error'] = 'The PHP curl extension is not installed.';

            return $res;
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->allowed($url)) {
                $res['error'] = $hop === 0 ? 'That address is not one this server may contact.' : 'The download was redirected to a host that is not allowed.';

                return $res;
            }
            $p = parse_url($url);
            $host = strtolower((string) $p['host']);
            $isTest = $this->testOrigin !== null && $host === strtolower((string) (parse_url($this->testOrigin)['host'] ?? ''));
            if ($authed && !$isTest && $host !== 'api.github.com') {
                $res['error'] = 'That address is not one this server may contact with a token.';

                return $res;
            }
            $port = (int) ($p['port'] ?? 443);
            $pin = [];
            if (!$isTest) {
                $ips = $this->publicAddresses($host);
                if ($ips === []) {
                    $res['error'] = "Could not resolve $host to a public address.";

                    return $res;
                }
                $pin = ["$host:$port:" . $ips[0]];   // connect to the address that was checked, not to a second DNS answer
            }
            $res['host'] = $host;
            $respHeaders = [];
            $bytes = 0;
            $fh = null;
            if ($toFile !== null) {
                $fh = @fopen($toFile, 'wb');
                if ($fh === false) {
                    $res['error'] = 'Could not create a temporary file.';

                    return $res;
                }
            }
            $body = '';
            $tooBig = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => max(5, $timeoutS),
                CURLOPT_USERAGENT => 'RivetMSP-agent-feed',
                CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $headers),
                CURLOPT_SSL_VERIFYPEER => !$isTest,
                CURLOPT_SSL_VERIFYHOST => $isTest ? 0 : 2,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders, &$tooBig, $maxBytes): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $k = strtolower(trim($parts[0]));
                        $respHeaders[$k] = trim($parts[1]);
                        if ($k === 'content-length' && ctype_digit(trim($parts[1])) && (int) trim($parts[1]) > $maxBytes) {
                            $tooBig = true;

                            return 0;   // abort before the body
                        }
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$bytes, &$body, &$tooBig, $maxBytes, $fh): int {
                    $bytes += strlen($chunk);
                    if ($bytes > $maxBytes) {
                        $tooBig = true;

                        return 0;
                    }
                    if ($fh !== null) {
                        return (int) fwrite($fh, $chunk);
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if ($postBody !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            }
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, $isTest ? 'http,https' : 'https');
            }
            if ($pin !== []) {
                curl_setopt($ch, CURLOPT_RESOLVE, $pin);
            }
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($fh !== null) {
                fclose($fh);
            }
            $res['headers'] = $respHeaders;
            $res['status'] = $status;
            $res['bytes'] = $bytes;
            if ($tooBig) {
                $res['error'] = 'The file is larger than the limit of ' . self::human($maxBytes) . '.';

                return $res;
            }
            if ($authed && $status >= 300 && $status < 400) {
                $res['error'] = 'GitHub answered with a redirect, which is not followed for a request that carries a token.';

                return $res;
            }
            if ($status >= 300 && $status < 400 && isset($respHeaders['location'])) {
                $next = $this->resolve($url, $respHeaders['location']);
                if ($next === null) {
                    $res['error'] = 'The server sent a redirect that could not be understood.';

                    return $res;
                }
                $url = $next;
                if ($toFile !== null) {
                    @unlink($toFile);
                }
                continue;
            }
            if ($ok === false) {
                $res['error'] = $err !== '' ? 'The request failed: ' . $err : 'The request failed.';

                return $res;
            }
            if (in_array($status, [403, 429], true) && ($respHeaders['x-ratelimit-remaining'] ?? '') === '0') {
                $res['rate_limited'] = true;
                $res['reset'] = ctype_digit($respHeaders['x-ratelimit-reset'] ?? '') ? (int) $respHeaders['x-ratelimit-reset'] : null;
                $res['error'] = 'GitHub\'s rate limit for this server is used up' . ($res['reset'] !== null ? ' until ' . gmdate('H:i', $res['reset']) . ' UTC' : '') . '.';
            }
            $res['body'] = $body;
            $res['ok'] = $status >= 200 && $status < 300;
            if (!$res['ok'] && $res['error'] === '' && $status !== 304) {
                $res['error'] = 'GitHub answered HTTP ' . $status . '.';
            }

            return $res;
        }
        $res['error'] = 'Too many redirects.';

        return $res;
    }

    /** A Location header resolved against the URL it came from (absolute, or host-relative). */
    private function resolve(string $base, string $location): ?string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || $location === '' || $location[0] !== '/' || str_starts_with($location, '//')) {
            return null;
        }

        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $location;
    }

    /** @return list<string> the host's addresses when ALL are public (none private, loopback, link-local or reserved); empty otherwise */
    private function publicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            $ips[] = (string) ($r['ip'] ?? '');
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];   // only IPv4 is pinned, which GitHub serves
        }
        $ips = array_values(array_filter($ips, static fn (string $i): bool => $i !== ''));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [];
            }
        }

        return $ips;
    }

    public static function human(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MiB' : ($n >= 1024 ? round($n / 1024, 1) . ' KiB' : $n . ' B');
    }
}

/** The release feed, its cache and the publish step. */
final class Feed
{
    public const DEFAULT_REPO = 'TheTractorHacker/rivet-core';
    public const REPO_RE = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9_.-]{1,100}\z/';   // a GitHub account name has letters, digits and dashes only
    public const TAG_RE = '/^agent-v(\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z][0-9A-Za-z.]{0,18})?)\z/';
    public const CACHE_TTL_S = 21600;        // the badge's cache: six hours
    public const MIN_RECHECK_S = 20;         // "Check for updates" pressed twice in a row costs one request
    public const API_MAX_BYTES = 3145728;
    public const SUMS_MAX_BYTES = 65536;

    /** Windows files this server hosts, and the Linux files it records. Anything else in a release is ignored. */
    public const WINDOWS_EXE = ['rivetit-agent-windows-amd64.exe' => 'amd64', 'rivetit-agent-windows-arm64.exe' => 'arm64'];
    public const WINDOWS_MSI = ['rivetit-agent-windows-amd64.msi' => 'amd64', 'rivetit-agent-windows-arm64.msi' => 'arm64'];

    private readonly FeedHttp $http;

    public function __construct(private readonly \mysqli $db, ?FeedHttp $http = null)
    {
        $origin = defined('RIVET_AGENT_FEED_TEST_ORIGIN') ? (string) constant('RIVET_AGENT_FEED_TEST_ORIGIN') : null;
        $this->http = $http ?? new FeedHttp($origin !== '' ? $origin : null);
    }

    // ------------------------------------------------------------------ settings and state

    public function ready(): bool
    {
        $r = @mysqli_query($this->db, "SHOW TABLES LIKE 'endpoint_agent_feed_state'");

        return $r !== false && mysqli_num_rows($r) > 0;
    }

    private function get(string $key): ?string
    {
        $st = $this->db->prepare('SELECT state_value FROM endpoint_agent_feed_state WHERE state_key = ?');
        if ($st === false) {
            return null;
        }
        $st->bind_param('s', $key);
        $st->execute();
        $row = $st->get_result()?->fetch_row();
        $st->close();

        return $row === null || $row === false ? null : (string) $row[0];
    }

    private function put(string $key, string $value): void
    {
        $st = $this->db->prepare('INSERT INTO endpoint_agent_feed_state (state_key, state_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = UTC_TIMESTAMP()');
        if ($st !== false) {
            $st->bind_param('ss', $key, $value);
            $st->execute();
            $st->close();
        }
    }

    /** 26.10.31: the same key/value store for the build feature (includes/rmm_agent_build.php). */
    public function stateGet(string $key): ?string
    {
        return $this->get($key);
    }

    public function statePut(string $key, string $value): void
    {
        $this->put($key, $value);
    }

    public function lock(string $name): bool
    {
        $r = @mysqli_query($this->db, "SELECT GET_LOCK('" . mysqli_real_escape_string($this->db, $name) . "', 0)");
        $row = $r ? $r->fetch_row() : null;

        return $row !== null && (int) $row[0] === 1;
    }

    public function unlock(string $name): void
    {
        @mysqli_query($this->db, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($this->db, $name) . "')");
    }

    public function http(): FeedHttp
    {
        return $this->http;
    }

    public function apiOrigin(): string
    {
        return $this->apiBase();
    }

    public function repo(): string
    {
        $r = $this->get('repo');

        return $r !== null && preg_match(self::REPO_RE, $r) === 1 ? $r : self::DEFAULT_REPO;
    }

    /** @return string|null an error message, null when stored */
    public function setRepo(string $repo): ?string
    {
        $repo = trim($repo);
        if (preg_match(self::REPO_RE, $repo) !== 1 || str_contains($repo, '..')) {
            return 'The repository must look like owner/name (letters, digits, dot, dash and underscore only).';
        }
        if ($repo !== $this->repo()) {
            $this->put('repo', $repo);
            $this->put('gh_token', '');   // 26.10.31: a build token belongs to one repository; another repository needs its own
            $this->put('gh_token_test', '');
            $this->put('build', '');
            $this->put('cache', '');   // another repository, another list
            $this->put('checked_at', '0');
        }

        return null;
    }

    public function badgeOn(): bool
    {
        return $this->get('badge') !== '0';
    }

    public function setBadge(bool $on): void
    {
        $this->put('badge', $on ? '1' : '0');
    }

    // ------------------------------------------------------------------ the release list

    /** @return array{fetched_at:int,etag:string,repo:string,items:list<array<string,mixed>>}|null */
    public function cached(): ?array
    {
        $raw = $this->get('cache');
        $d = $raw !== null && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['items']) || !is_array($d['items']) || ($d['repo'] ?? '') !== $this->repo()) {
            return null;
        }

        return ['fetched_at' => (int) ($d['fetched_at'] ?? 0), 'etag' => (string) ($d['etag'] ?? ''), 'repo' => (string) $d['repo'], 'items' => array_values($d['items'])];
    }

    public function lastError(): string
    {
        return (string) ($this->get('last_error') ?? '');
    }

    /**
     * Ask GitHub for the releases (agent-v* tags only are kept). On any failure the cached list stays and is reported as old.
     *
     * @return array{ok:bool,message:string,from_cache:bool,rate_limited:bool}
     */
    public function refresh(bool $force, int $timeoutS = 15): array
    {
        $cache = $this->cached();
        $checked = (int) ($this->get('checked_at') ?? 0);
        $now = time();
        if ($cache !== null && !$force && $now - $checked < self::CACHE_TTL_S) {
            return ['ok' => true, 'message' => 'Using the list fetched ' . self::ago($now - $checked) . '.', 'from_cache' => true, 'rate_limited' => false];
        }
        if ($force && $cache !== null && $now - $checked < self::MIN_RECHECK_S) {
            return ['ok' => true, 'message' => 'Checked just now.', 'from_cache' => true, 'rate_limited' => false];
        }
        $repo = $this->repo();
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($cache !== null && $cache['etag'] !== '') {
            $headers[] = 'If-None-Match: ' . $cache['etag'];
        }
        $r = $this->http->get($this->apiBase() . "/repos/$repo/releases?per_page=100", self::API_MAX_BYTES, $timeoutS, $headers);
        $this->put('checked_at', (string) $now);   // also on a failure: an offline server is not asked on every page load
        if ($r['status'] === 304 && $cache !== null) {
            $cache['fetched_at'] = $now;
            $this->put('cache', (string) json_encode($cache));
            $this->put('last_error', '');

            return ['ok' => true, 'message' => 'Nothing new on GitHub.', 'from_cache' => false, 'rate_limited' => false];
        }
        if (!$r['ok']) {
            $msg = $r['error'] !== '' ? $r['error'] : 'GitHub could not be reached.';
            $this->put('last_error', $msg);

            return ['ok' => false, 'message' => $msg . ($cache !== null ? ' Showing the list from ' . self::ago($now - $cache['fetched_at']) . '.' : ''), 'from_cache' => $cache !== null, 'rate_limited' => $r['rate_limited']];
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            $this->put('last_error', 'GitHub sent an answer that is not a release list.');

            return ['ok' => false, 'message' => 'GitHub sent an answer that is not a release list.' . ($cache !== null ? ' Showing the earlier list.' : ''), 'from_cache' => $cache !== null, 'rate_limited' => false];
        }
        $items = [];
        foreach ($data as $rel) {
            $n = is_array($rel) ? self::normalise($rel, $repo) : null;
            if ($n !== null) {
                $items[] = $n;
            }
        }
        usort($items, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
        $items = array_slice($items, 0, 40);
        $this->put('cache', (string) json_encode(['fetched_at' => $now, 'etag' => $r['headers']['etag'] ?? '', 'repo' => $repo, 'items' => $items]));
        $this->put('last_error', '');

        return ['ok' => true, 'message' => count($items) . ' agent release' . (count($items) === 1 ? '' : 's') . ' found.', 'from_cache' => false, 'rate_limited' => false];
    }

    private function apiBase(): string
    {
        return defined('RIVET_AGENT_FEED_TEST_ORIGIN') && (string) constant('RIVET_AGENT_FEED_TEST_ORIGIN') !== '' ? rtrim((string) constant('RIVET_AGENT_FEED_TEST_ORIGIN'), '/') : 'https://api.github.com';
    }

    private function webBase(): string
    {
        return defined('RIVET_AGENT_FEED_TEST_ORIGIN') && (string) constant('RIVET_AGENT_FEED_TEST_ORIGIN') !== '' ? rtrim((string) constant('RIVET_AGENT_FEED_TEST_ORIGIN'), '/') : 'https://github.com';
    }

    /**
     * One GitHub release as the page keeps it: only agent-v* tags, no drafts, only the assets this feature knows, bounded text. Everything else GitHub sent is dropped.
     *
     * @param array<string,mixed> $rel
     * @return array<string,mixed>|null
     */
    public static function normalise(array $rel, string $repo): ?array
    {
        $tag = is_string($rel['tag_name'] ?? null) ? $rel['tag_name'] : '';
        if (preg_match(self::TAG_RE, $tag, $m) !== 1 || !empty($rel['draft'])) {
            return null;
        }
        $version = $m[1];
        $assets = [];
        foreach ((array) ($rel['assets'] ?? []) as $a) {
            $name = is_array($a) && is_string($a['name'] ?? null) ? $a['name'] : '';
            $kind = self::kindOf($name, $version);
            if ($kind === null) {
                continue;
            }
            $digest = is_string($a['digest'] ?? null) && preg_match('/^sha256:([0-9a-fA-F]{64})\z/', $a['digest'], $dm) === 1 ? strtolower($dm[1]) : '';
            $assets[] = ['name' => $name, 'kind' => $kind['kind'], 'arch' => $kind['arch'], 'size' => max(0, (int) ($a['size'] ?? 0)), 'digest' => $digest];
        }
        $published = is_string($rel['published_at'] ?? null) ? $rel['published_at'] : (is_string($rel['created_at'] ?? null) ? $rel['created_at'] : '');

        return [
            'tag' => $tag,
            'version' => $version,
            'prerelease' => !empty($rel['prerelease']),
            'published_at' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $published) === 1 ? $published : '',
            'name' => mb_substr(is_string($rel['name'] ?? null) ? $rel['name'] : $tag, 0, 120),
            'notes' => mb_substr(trim((string) preg_replace('/\s+/', ' ', is_string($rel['body'] ?? null) ? $rel['body'] : '')), 0, 300),
            'url' => "https://github.com/$repo/releases/tag/$tag",
            'assets' => $assets,
        ];
    }

    /** @return array{kind:string,arch:string}|null what an asset file name is, or null when this feature ignores it */
    public static function kindOf(string $name, string $version): ?array
    {
        if (isset(self::WINDOWS_EXE[$name])) {
            return ['kind' => 'exe', 'arch' => self::WINDOWS_EXE[$name]];
        }
        if (isset(self::WINDOWS_MSI[$name])) {
            return ['kind' => 'msi', 'arch' => self::WINDOWS_MSI[$name]];
        }
        if ($name === 'SHA256SUMS' || $name === 'SHA256SUMS.msi') {
            return ['kind' => 'sums', 'arch' => ''];
        }
        if ($name === 'install-linux.sh') {
            return ['kind' => 'script', 'arch' => ''];
        }
        if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\.tar\.gz\z/', $name, $m) === 1) {
            return ['kind' => 'tarball', 'arch' => $m[1]];
        }
        if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\z/', $name, $m) === 1) {
            return ['kind' => 'binary', 'arch' => $m[1]];
        }
        $v = preg_quote($version, '/');
        if (preg_match('/^rivetit-agent_' . $v . '_(amd64|arm64)\.deb\z/', $name, $m) === 1) {
            return ['kind' => 'deb', 'arch' => $m[1]];
        }
        if (preg_match('/^rivetit-agent-' . $v . '-\d{1,3}\.(x86_64|aarch64)\.rpm\z/', $name, $m) === 1) {
            return ['kind' => 'rpm', 'arch' => $m[1] === 'x86_64' ? 'amd64' : 'arm64'];
        }

        return null;
    }

    public function downloadUrl(string $tag, string $name): string
    {
        return $this->webBase() . '/' . $this->repo() . '/releases/download/' . $tag . '/' . $name;
    }

    /** The newest release in the cache, or null. @return array<string,mixed>|null */
    public function newest(): ?array
    {
        $c = $this->cached();

        return $c === null || $c['items'] === [] ? null : $c['items'][0];
    }

    // ------------------------------------------------------------------ what is already here

    /**
     * The state of this installation, by version: which Windows files are hosted, which releases are offered to which ring, whether the Linux links are recorded, and how many
     * devices report each version.
     *
     * @return array{exe:array<string,array<string,bool>>,msi:array<string,array<string,bool>>,rings:array<string,list<array<string,mixed>>>,linux:array<string,array<string,mixed>>,devices:array<string,int>,highest:?string}
     */
    public function published(): array
    {
        $out = ['exe' => [], 'msi' => [], 'rings' => [], 'linux' => [], 'devices' => [], 'highest' => null];
        $q = static function (\mysqli $db, string $sql): array {
            $rows = [];
            $r = @mysqli_query($db, $sql);
            while ($r && ($row = mysqli_fetch_assoc($r))) {
                $rows[] = $row;
            }

            return $rows;
        };
        foreach ($q($this->db, 'SELECT version, arch FROM endpoint_agent_binaries WHERE active = 1') as $r) {
            $out['exe'][(string) $r['version']][(string) $r['arch']] = true;
            if ($out['highest'] === null || version_compare((string) $r['version'], $out['highest']) > 0) {
                $out['highest'] = (string) $r['version'];
            }
        }
        foreach ($q($this->db, 'SELECT version, arch FROM endpoint_agent_msi_templates WHERE active = 1') as $r) {
            $out['msi'][(string) $r['version']][(string) $r['arch']] = true;
        }
        foreach ($q($this->db, 'SELECT version, arch, ring, rollout_pct, active FROM endpoint_agent_releases WHERE active = 1') as $r) {
            $out['rings'][(string) $r['version']][] = ['arch' => (string) $r['arch'], 'ring' => (string) $r['ring'], 'pct' => (int) $r['rollout_pct']];
        }
        foreach ($q($this->db, "SELECT state_key, state_value FROM endpoint_agent_feed_state WHERE state_key LIKE 'linux:%'") as $r) {
            $d = json_decode((string) $r['state_value'], true);
            if (is_array($d)) {
                $out['linux'][substr((string) $r['state_key'], 6)] = $d;
            }
        }
        foreach ($q($this->db, 'SELECT agent_version, COUNT(*) AS c FROM endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL GROUP BY agent_version') as $r) {
            $v = ltrim(explode('+', (string) $r['agent_version'])[0], 'vV');
            $out['devices'][$v] = ($out['devices'][$v] ?? 0) + (int) $r['c'];
        }

        return $out;
    }

    /**
     * The rows of the "Agent updates" card.
     *
     * @return array<string,mixed>
     */
    public function view(): array
    {
        $cache = $this->cached();
        $pub = $this->published();
        $rows = [];
        foreach ($cache['items'] ?? [] as $it) {
            $v = (string) $it['version'];
            $kinds = [];
            foreach ($it['assets'] as $a) {
                $kinds[$a['kind']][] = $a;
            }
            $have = [
                'exe' => array_keys($pub['exe'][$v] ?? []),
                'msi' => array_keys($pub['msi'][$v] ?? []),
                'linux' => isset($pub['linux'][$v]),
            ];
            $wantExe = array_values(array_unique(array_map(static fn (array $a): string => $a['arch'], $kinds['exe'] ?? [])));
            $wantMsi = array_values(array_unique(array_map(static fn (array $a): string => $a['arch'], $kinds['msi'] ?? [])));
            $complete = $wantExe !== [] && array_diff($wantExe, $have['exe']) === [] && array_diff($wantMsi, $have['msi']) === [] && (!isset($kinds['script']) && !isset($kinds['tarball']) || $have['linux']);
            $rows[] = $it + ['have' => $have, 'rings' => $pub['rings'][$v] ?? [], 'devices' => (int) ($pub['devices'][$v] ?? 0), 'complete' => $complete,
                'publishable' => $wantExe !== [] || $wantMsi !== [] || isset($kinds['script']) || isset($kinds['tarball']), 'has_sums' => isset($kinds['sums'])];
        }
        $newest = $cache['items'][0] ?? null;
        $newer = $newest !== null && ($pub['highest'] === null || version_compare((string) $newest['version'], $pub['highest']) > 0);

        return ['repo' => $this->repo(), 'badge' => $this->badgeOn(), 'rows' => $rows, 'fetched_at' => $cache['fetched_at'] ?? 0, 'error' => $this->lastError(), 'devices' => $pub['devices'],
            'highest' => $pub['highest'], 'newer' => $newer ? (string) $newest['version'] : null, 'newer_pre' => $newer && !empty($newest['prerelease']), 'has_cache' => $cache !== null];
    }

    // ------------------------------------------------------------------ publish

    /** @return array<string,string> file name => sha256, from a SHA256SUMS text (the sha256sum format: 64 hex, blank or " *", then a plain file name) */
    public static function parseSums(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64})[ \t]+\*?([A-Za-z0-9][A-Za-z0-9_.+-]{0,150})\s*\z/', $line, $m) === 1) {
                $out[$m[2]] = strtolower($m[1]);
            }
        }

        return $out;
    }

    /**
     * Publish one release into this installation.
     *
     * Order: (1) the checksum lists, (2) every Windows file downloaded and its SHA-256 compared with the list, ALL before anything is stored, (3) the verified files go to
     * RmmAdmin::uploadBinary() / uploadMsiTemplate(), (4) the Linux links are recorded. A file whose hash does not match is never stored, and one bad file stops the whole
     * publish before step 3.
     *
     * @param object $admin RivetCore\Rmm\Admin\RmmAdmin
     * @param object $who RivetCore\Rmm\Authz\RmmPrincipal
     * @param array{ring:string,pct:int,activate:bool,max_bytes:int,service_ready:bool} $opts pct is the rollout percent of the ring offer ring '' (no update offer), pilot or stable
     * @return array{ok:bool,message:string,steps:list<array{label:string,state:string,detail:string}>}
     */
    public function publish(object $admin, object $who, string $tag, array $opts): array
    {
        $steps = [];
        $fail = static function (string $msg) use (&$steps): array {
            return ['ok' => false, 'message' => $msg, 'steps' => $steps];
        };
        if (preg_match(self::TAG_RE, $tag, $tm) !== 1) {
            return $fail('That is not an agent release tag.');
        }
        $version = $tm[1];
        $item = null;
        foreach ($this->cached()['items'] ?? [] as $it) {
            if ($it['tag'] === $tag) {
                $item = $it;
            }
        }
        if ($item === null) {
            return $fail('That release is not in the list. Press Check for updates and try again.');
        }
        $ring = (string) $opts['ring'];
        if (!in_array($ring, ['', 'pilot', 'stable'], true)) {
            return $fail('Choose pilot, stable or no update offer.');
        }
        if ($ring !== '' && empty($opts['service_ready'])) {
            return $fail('Set an https service URL first (Service and defaults): agents download hosted updates from it. Nothing was published.');
        }
        $byName = [];
        foreach ($item['assets'] as $a) {
            $byName[$a['name']] = $a;
        }
        $wantExe = array_values(array_filter(array_keys(self::WINDOWS_EXE), static fn (string $n): bool => isset($byName[$n])));
        $wantMsi = array_values(array_filter(array_keys(self::WINDOWS_MSI), static fn (string $n): bool => isset($byName[$n])));
        if ($wantExe === [] && $wantMsi === [] && !isset($byName['install-linux.sh'])) {
            return $fail('This release has no files this server can use.');
        }

        // (1) the checksum lists
        $sums = [];
        foreach (['SHA256SUMS', 'SHA256SUMS.msi'] as $sf) {
            if (!isset($byName[$sf])) {
                continue;
            }
            $r = $this->http->get($this->downloadUrl($tag, $sf), self::SUMS_MAX_BYTES, 30);
            if (!$r['ok']) {
                $steps[] = ['label' => $sf, 'state' => 'failed', 'detail' => $r['error']];

                return $fail("Could not read $sf: " . $r['error'] . ' Nothing was published.');
            }
            $parsed = self::parseSums($r['body']);
            $steps[] = ['label' => $sf, 'state' => 'ok', 'detail' => count($parsed) . ' checksums read'];
            $sums += $parsed;   // a name in both lists keeps the first (SHA256SUMS) value
            if ($sf === 'SHA256SUMS.msi') {
                foreach ($parsed as $n => $h) {
                    if (isset($sums[$n]) && $sums[$n] !== $h) {
                        return $fail("$n has two different checksums in the release's SHA256SUMS and SHA256SUMS.msi. Nothing was published.");
                    }
                }
            }
        }

        // (2) download and verify every Windows file BEFORE anything is stored
        $tmp = [];
        $cleanup = static function () use (&$tmp): void {
            foreach ($tmp as $f) {
                @unlink($f);
            }
        };
        $verified = [];
        foreach (array_merge($wantExe, $wantMsi) as $name) {
            $a = $byName[$name];
            if (!isset($sums[$name])) {
                $steps[] = ['label' => $name, 'state' => 'failed', 'detail' => 'not listed in the release checksums'];
                $cleanup();

                return $fail("$name is not listed in the release's checksum files, so it cannot be verified. Nothing was published.");
            }
            if ($a['digest'] !== '' && $a['digest'] !== $sums[$name]) {
                $steps[] = ['label' => $name, 'state' => 'failed', 'detail' => 'GitHub\'s own digest disagrees with the checksum list'];
                $cleanup();

                return $fail("GitHub's digest for $name disagrees with the release's checksum list. Nothing was published.");
            }
            $file = tempnam(sys_get_temp_dir(), 'rvfeed');
            if ($file === false) {
                $cleanup();

                return $fail('Could not create a temporary file. Nothing was published.');
            }
            $tmp[] = $file;
            @chmod($file, 0600);
            $r = $this->http->get($this->downloadUrl($tag, $name), (int) $opts['max_bytes'], 180, [], $file);
            if (!$r['ok']) {
                $steps[] = ['label' => $name, 'state' => 'failed', 'detail' => $r['error']];
                $cleanup();

                return $fail("Could not download $name: " . $r['error'] . ' Nothing was published.');
            }
            $got = hash_file('sha256', $file);
            if (!is_string($got) || !hash_equals($sums[$name], strtolower($got))) {
                $steps[] = ['label' => $name, 'state' => 'failed', 'detail' => 'SHA-256 does not match the release checksum'];
                $cleanup();

                return $fail("The SHA-256 of $name does not match the release's checksum list. It was not stored and nothing was published.");
            }
            $steps[] = ['label' => $name, 'state' => 'ok', 'detail' => 'downloaded ' . FeedHttp::human((int) filesize($file)) . ', SHA-256 verified'];
            $verified[$name] = $file;
        }

        // (3) store through the existing code paths (they authorize, validate the file again and audit)
        $stored = 0;
        $problems = 0;
        foreach ($wantExe as $name) {
            $arch = self::WINDOWS_EXE[$name];
            $res = $admin->uploadBinary($who, $verified[$name], $version, $arch, ['activate' => !empty($opts['activate']), 'release_ring' => $ring === '' ? null : $ring, 'rollout_pct' => max(0, min(100, (int) $opts['pct']))]);
            $steps[] = ['label' => "store $name", 'state' => $res->ok ? 'ok' : 'failed', 'detail' => $res->message];
            $res->ok ? $stored++ : $problems++;
        }
        foreach ($wantMsi as $name) {
            $arch = self::WINDOWS_MSI[$name];
            $res = $admin->uploadMsiTemplate($who, $verified[$name], $version, $arch, ['activate' => !empty($opts['activate'])]);
            $steps[] = ['label' => "store $name", 'state' => $res->ok ? 'ok' : 'failed', 'detail' => $res->message];
            $res->ok ? $stored++ : $problems++;
        }
        $cleanup();

        // (4) the Linux links (only when every Windows step above went through: a release that failed half way does not become "the newest recorded release")
        $linux = [];
        foreach ($problems > 0 ? [] : $item['assets'] as $a) {
            if (!in_array($a['kind'], ['script', 'tarball', 'binary', 'deb', 'rpm'], true)) {
                continue;
            }
            $sha = $sums[$a['name']] ?? '';
            $source = $sha !== '' ? 'checksum list' : '';
            if ($sha !== '' && $a['digest'] !== '' && $a['digest'] !== $sha) {
                $steps[] = ['label' => $a['name'], 'state' => 'failed', 'detail' => 'GitHub\'s own digest disagrees with the checksum list; not recorded'];
                $problems++;
                continue;
            }
            if ($sha === '' && $a['digest'] !== '') {
                $sha = $a['digest'];
                $source = 'GitHub digest';
            }
            $linux[] = ['name' => $a['name'], 'kind' => $a['kind'], 'arch' => $a['arch'], 'size' => $a['size'], 'sha256' => $sha, 'sha_source' => $source, 'url' => $this->downloadUrl($tag, $a['name'])];
        }
        if ($problems > 0) {
            $steps[] = ['label' => 'Linux files', 'state' => 'skipped', 'detail' => 'not recorded because a step above failed'];
        }
        if ($linux !== []) {
            $this->put('linux:' . $version, (string) json_encode(['tag' => $tag, 'version' => $version, 'prerelease' => $item['prerelease'], 'recorded_at' => gmdate('Y-m-d H:i:s'), 'recorded_by' => (string) ($who->userName ?? ''), 'assets' => $linux]));
            $steps[] = ['label' => 'Linux files', 'state' => 'ok', 'detail' => count($linux) . ' recorded as links (' . count(array_filter($linux, static fn (array $l): bool => $l['sha256'] !== '')) . ' with a SHA-256)'];
        }

        if ($stored === 0 && $linux === []) {
            return ['ok' => false, 'message' => 'Nothing was stored. ' . ($steps !== [] ? (string) end($steps)['detail'] : ''), 'steps' => $steps];
        }

        return ['ok' => $problems === 0, 'message' => ($problems === 0 ? "Release $version published." : "Release $version published with $problems problem" . ($problems === 1 ? '' : 's') . ': see the steps.'), 'steps' => $steps];
    }

    /** The recorded Linux assets of the newest version that has any, or the one asked for. @return array<string,mixed>|null */
    public function linuxRecord(?string $version = null): ?array
    {
        $pub = $this->published()['linux'];
        if ($pub === []) {
            return null;
        }
        if ($version !== null) {
            return $pub[$version] ?? null;
        }
        uksort($pub, static fn (string $a, string $b): int => version_compare($b, $a));

        return reset($pub) ?: null;
    }

    public static function ago(int $s): string
    {
        $s = max(0, $s);

        return $s < 90 ? 'just now' : ($s < 5400 ? round($s / 60) . ' minutes ago' : ($s < 172800 ? round($s / 3600) . ' hours ago' : round($s / 86400) . ' days ago'));
    }
}

/**
 * The Linux install commands of the "Build installer" page, built from the Linux assets a published release recorded (Feed::publish) and the enrollment token the page
 * just created. Every command downloads over https from the release, checks the SHA-256 recorded at publish time, and only then runs anything. The token never appears on a
 * command line: it goes through a root-only temporary file, like RivetCore's own Linux snippet (which the tarball route reuses verbatim).
 */
final class LinuxInstall
{
    /** @param array<string,mixed> $record Feed::linuxRecord() @return array<string,array<string,mixed>> arch => asset, of one kind (only assets with a SHA-256) */
    public static function byArch(array $record, string $kind): array
    {
        $out = [];
        foreach ((array) ($record['assets'] ?? []) as $a) {
            if (is_array($a) && ($a['kind'] ?? '') === $kind && preg_match('/^[0-9a-f]{64}\z/', (string) ($a['sha256'] ?? '')) === 1 && in_array($a['arch'] ?? '', ['amd64', 'arm64'], true)) {
                $out[(string) $a['arch']] = $a;
            }
        }

        return $out;
    }

    private static function q(string $v): string
    {
        return \RivetCore\Rmm\Installer\InstallerService::shQuote($v);
    }

    /** The shell `case` that picks the file for this CPU. @param array<string,array<string,mixed>> $files */
    private static function pick(array $files): string
    {
        $branch = static fn (array $f): string => 'U=' . self::q((string) $f['url']) . '; H=' . self::q((string) $f['sha256']) . ';;';
        $o = "case \"\$(uname -m)\" in\n";
        $o .= isset($files['amd64']) ? '  x86_64|amd64) ' . $branch($files['amd64']) . "\n" : '';
        $o .= isset($files['arm64']) ? '  aarch64|arm64) ' . $branch($files['arm64']) . "\n" : '';

        return $o . "  *) echo 'RivetIT agent: no build of this release for this CPU (' \"\$(uname -m)\" ')' >&2; exit 1;;\nesac\n";
    }

    /**
     * Any distribution with systemd: the release tarball (agent + install-linux.sh), verified, unpacked in a private directory, then RivetCore's own snippet (token file, CA,
     * optional Mesh agent) runs in a subshell. Null when no tarball with a SHA-256 is recorded.
     *
     * @param array<string,mixed> $record
     */
    public static function script(array $record, string $coreSnippet, string $department): ?string
    {
        $files = self::byArch($record, 'tarball');
        if ($files === []) {
            return null;
        }
        $label = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));

        return "# RivetIT agent install for client: $label (Linux, systemd). Run as root. Release " . (string) ($record['version'] ?? '') . ': the tarball is downloaded from the release and its SHA-256 is checked before anything runs.' . "\n"
            . "set -eu\nD=\"\$(mktemp -d)\"\ntrap 'rm -rf \"\$D\"' EXIT\ncd \"\$D\"\n"
            . self::pick($files)
            . "curl -fsSL --proto '=https' --proto-redir '=https' -o agent.tar.gz \"\$U\"\n"
            . "echo \"\$H  agent.tar.gz\" | sha256sum -c -\n"
            . "tar xzf agent.tar.gz\n"
            . "(\n" . rtrim($coreSnippet) . "\n)\n";
    }

    /**
     * Debian / Ubuntu (.deb) or RPM-based (.rpm): the package installs the binary and enrolls nothing, so the packaged agent's own `install` command enrolls it. Null when no
     * such package with a SHA-256 is recorded.
     *
     * @param array<string,mixed> $record
     */
    public static function package(array $record, string $kind, string $coreSnippet, string $token, string $server, ?string $caPem, string $department): ?string
    {
        $files = self::byArch($record, $kind);
        if ($files === [] || !in_array($kind, ['deb', 'rpm'], true)) {
            return null;
        }
        $label = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));
        $ca = $caPem !== null && trim($caPem) !== '';
        // RivetCore's snippet ends with the install-linux.sh call; whatever follows the token and CA arguments there (the Mesh agent flags) is passed on to `install`.
        $mesh = preg_match('/^\.\/install-linux\.sh --server \S+ --token-file "\$T"(?: --ca "\$C")?(.*)$/m', $coreSnippet, $m) === 1 ? rtrim($m[1]) : '';
        $install = $kind === 'deb'
            ? "apt-get install -y \"\$D/agent.deb\"\n"
            : "if command -v dnf >/dev/null 2>&1; then dnf install -y \"\$D/agent.rpm\"; elif command -v yum >/dev/null 2>&1; then yum install -y \"\$D/agent.rpm\"; else rpm -U \"\$D/agent.rpm\"; fi\n";

        return "# RivetIT agent install for client: $label (" . ($kind === 'deb' ? 'Debian / Ubuntu package' : 'RPM package') . '). Run as root. Release ' . (string) ($record['version'] ?? '') . ": the package is downloaded from the release and its SHA-256 is checked before it is installed.\n"
            . "# The package does not enroll the machine; the last line does. The token is a secret: do not paste it into tickets or chat.\n"
            . "set -eu\numask 077\nD=\"\$(mktemp -d)\"\ntrap 'rm -rf \"\$D\"' EXIT\n"
            . self::pick($files)
            . "curl -fsSL --proto '=https' --proto-redir '=https' -o \"\$D/agent." . $kind . "\" \"\$U\"\n"
            . "echo \"\$H  \$D/agent." . $kind . "\" | sha256sum -c -\n"
            . "printf '%s' " . self::q($token) . " > \"\$D/token\"\n"
            . ($ca ? 'printf \'%s\' ' . \RivetCore\Rmm\Installer\InstallerService::shQuote(rtrim((string) $caPem) . "\n", true) . " > \"\$D/ca.pem\"\n" : '')
            . "umask 022\n"
            . $install
            . '/opt/rivetit-agent/rivetit-agent install --server ' . self::q(rtrim($server, '/')) . ' --token-file "$D/token"' . ($ca ? ' --ca "$D/ca.pem"' : '') . $mesh . "\n";
    }
}
