<?php

/*
 * Administration > Endpoint agent > Agent updates > "Build a new version" (26.10.31).
 *
 * Starts RivetCore's workflow .github/workflows/endpoint-agent.yml (workflow_dispatch, one required input "version") through GitHub's REST API, follows the run, and, when the
 * release agent-v<version> shows up, hands it to the existing Publish code (Feed::publish), optionally by itself.
 *
 * The GitHub token (fine-grained, "Actions: Read and write" on the one repository) is stored ENCRYPTED (encryptSetting(), context agent_feed_token) in
 * endpoint_agent_feed_state, key gh_token. It is never rendered, logged, put in an audit row, a flash message or an error text, and it is sent only to api.github.com
 * (FeedHttp::get() refuses any other host for a request that carries an Authorization header, and never follows a redirect for one). The repository is always Feed::repo(),
 * the stored and validated setting, never a request field; the workflow file name and the ref are constants.
 *
 * No database change: the state table of 26.10.28 (DB 2.6.98) has room for the keys gh_token, gh_token_test and build.
 */

namespace RivetMsp\AgentFeed;

require_once __DIR__ . '/rmm_agent_feed.php';

final class Build
{
    public const WORKFLOW = 'endpoint-agent.yml';
    public const REF = 'main';
    public const CTX = 'agent_feed_token';
    public const TOKEN_RE = '/^github_pat_[A-Za-z0-9_]{22,255}\z/';
    public const VERSION_RE = '/^(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})(?:-[0-9A-Za-z][0-9A-Za-z.]{0,18})?\z/D';
    public const POLL_MIN_S = 4;          // a poll sooner than this after the last one answers from the stored state
    public const RUN_SEARCH_S = 300;      // after this long without a run appearing, say so
    public const ACTIVE_MAX_S = 7200;     // a build older than this no longer blocks a new one
    public const TERMINAL = ['finished', 'failed', 'lost'];

    /** @var callable(string,string):void */
    private $audit;

    /** @param callable(string,string):void|null $audit (action, text) */
    public function __construct(private readonly Feed $feed, ?callable $audit = null)
    {
        $this->audit = $audit ?? static function (string $a, string $t): void {
        };
    }

    // ------------------------------------------------------------------ the token

    public function hasToken(): bool
    {
        return (string) ($this->feed->stateGet('gh_token') ?? '') !== '';
    }

    /** @return string|null an error message, null when stored */
    public function saveToken(#[\SensitiveParameter] string $token): ?string
    {
        $token = trim($token);
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            return 'That does not look like a fine-grained GitHub token (it starts with github_pat_ and has no spaces). Nothing was saved.';
        }
        try {
            $enc = encryptSetting($token, self::CTX);
        } catch (\Throwable) {
            return 'The token could not be stored encrypted (the settings encryption key is missing), so nothing was saved.';
        }
        if ($enc === '' || $enc === $token || str_contains($enc, $token)) {
            return 'The token could not be stored encrypted, so nothing was saved.';
        }
        $this->feed->statePut('gh_token', $enc);
        $this->feed->statePut('gh_token_test', '');

        return null;
    }

    public function removeToken(): void
    {
        $this->feed->statePut('gh_token', '');
        $this->feed->statePut('gh_token_test', '');
    }

    private function token(): string
    {
        $raw = (string) ($this->feed->stateGet('gh_token') ?? '');
        if ($raw === '') {
            return '';
        }
        $t = decryptSetting($raw, self::CTX);

        return preg_match(self::TOKEN_RE, $t) === 1 ? $t : '';
    }

    /** @return array{ok:bool,at:int,repo:string,message:string,expires:string}|null the last "Test token" result (never the token) */
    public function lastTest(): ?array
    {
        $d = json_decode((string) ($this->feed->stateGet('gh_token_test') ?? ''), true);
        if (!is_array($d) || ($d['repo'] ?? '') !== $this->feed->repo()) {
            return null;
        }

        return ['ok' => (bool) ($d['ok'] ?? false), 'at' => (int) ($d['at'] ?? 0), 'repo' => (string) $d['repo'], 'message' => (string) ($d['message'] ?? ''), 'expires' => (string) ($d['expires'] ?? '')];
    }

    // ------------------------------------------------------------------ GitHub calls (api.github.com only, through FeedHttp)

    /** @return array<string,mixed> FeedHttp::get() result */
    private function call(string $path, ?array $json = null, int $timeoutS = 12): array
    {
        $tok = $this->token();
        if ($tok === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'No GitHub token is stored.', 'headers' => [], 'rate_limited' => false];
        }
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'Authorization: Bearer ' . $tok];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = (string) json_encode($json);
        }

        return $this->feed->http()->get($this->feed->apiOrigin() . $path, 262144, $timeoutS, $headers, null, $body);
    }

    private function repoPath(): string
    {
        return '/repos/' . $this->feed->repo();   // Feed::repo() only returns a value that matches REPO_RE
    }

    /** A short plain-language reason for a failed GitHub call. GitHub's own "message" is printable text cut to 160 characters; it never holds our token. */
    private static function explain(array $r, string $repo, string $what): string
    {
        $gh = '';
        $d = json_decode((string) ($r['body'] ?? ''), true);
        if (is_array($d) && isset($d['message']) && is_string($d['message'])) {
            $gh = trim((string) preg_replace('/[^\x20-\x7e]/', '', mb_substr($d['message'], 0, 160)));
        }
        $st = (int) ($r['status'] ?? 0);
        if (!empty($r['rate_limited'])) {
            return (string) $r['error'];
        }

        return match (true) {
            $st === 401 => 'GitHub rejected the token (it is wrong, expired or was revoked). Create a new one and save it.',
            $st === 403 => "The token is valid but not allowed to $what. Give it the permission Actions: Read and write on $repo.",
            $st === 404 => "GitHub cannot find $repo or its workflow with this token. Check the repository name above, and that the token has access to exactly this repository.",
            $st === 422 => "GitHub refused the request: the workflow may not allow a manual run on the main branch or may not accept the version.",
            $st >= 300 && $st < 400 && (string) $r['error'] !== '' => (string) $r['error'],
            $st > 0 => 'GitHub answered HTTP ' . $st . '.',
            default => (string) ($r['error'] !== '' ? $r['error'] : 'GitHub could not be reached.'),
        } . ($gh !== '' && $st > 0 ? ' GitHub says: ' . $gh : '');
    }

    /** @return array{ok:bool,message:string} */
    public function testToken(): array
    {
        $repo = $this->feed->repo();
        $res = ['ok' => false, 'message' => ''];
        if (!$this->hasToken()) {
            return ['ok' => false, 'message' => 'No token is stored yet.'];
        }
        $r = $this->call($this->repoPath() . '/actions/workflows/' . self::WORKFLOW);
        $expires = '';
        if ($r['ok']) {
            $d = json_decode((string) $r['body'], true);
            $state = is_array($d) ? (string) ($d['state'] ?? '') : '';
            $exp = (string) ($r['headers']['github-authentication-token-expiration'] ?? '');
            $expires = preg_match('/^\d{4}-\d{2}-\d{2}/', $exp) === 1 ? substr($exp, 0, 10) : '';
            if ($state === 'active') {
                $res = ['ok' => true, 'message' => "The token works: it can see the workflow in $repo." . ($expires !== '' ? " It expires $expires." : '') . ' Whether it may also start a build is proven by the first build.'];
            } else {
                $res['message'] = 'The token can see the workflow, but GitHub reports it as ' . ($state !== '' ? preg_replace('/[^a-z_]/', '', $state) : 'not active') . '. Enable it on GitHub (Actions tab).';
            }
        } else {
            $res['message'] = self::explain($r, $repo, 'read the repository\'s workflows');
        }
        $this->feed->statePut('gh_token_test', (string) json_encode(['ok' => $res['ok'], 'at' => time(), 'repo' => $repo, 'message' => $res['message'], 'expires' => $expires]));
        ($this->audit)('Agent Build Token Tested', 'the GitHub build token was tested against ' . $repo . ': ' . ($res['ok'] ? 'works' : 'failed'));

        return $res;
    }

    // ------------------------------------------------------------------ versions

    public static function validVersion(string $v): bool
    {
        return preg_match(self::VERSION_RE, $v) === 1;
    }

    /** @param list<array<string,mixed>> $items Feed::cached()['items'] */
    public static function latest(array $items): ?string
    {
        $best = null;
        foreach ($items as $it) {
            $v = (string) ($it['version'] ?? '');
            if (self::validVersion($v) && ($best === null || version_compare($v, $best) > 0)) {
                $best = $v;
            }
        }

        return $best;
    }

    /** The next patch version: 0.9.1 -> 0.9.2; a pre-release 0.9.2-rc1 -> 0.9.2; nothing known -> 0.1.0. */
    public static function suggest(?string $latest): string
    {
        if ($latest === null || preg_match('/^(\d+)\.(\d+)\.(\d+)(-.*)?\z/', $latest, $m) !== 1) {
            return '0.1.0';
        }

        return $m[1] . '.' . $m[2] . '.' . ((int) $m[3] + (isset($m[4]) && $m[4] !== '' ? 0 : 1));
    }

    /** @param list<array<string,mixed>> $items @return string|null the reason the version is refused */
    public static function checkVersion(string $v, array $items): ?string
    {
        if (!self::validVersion($v)) {
            return 'The version must look like 0.9.2 (numbers separated by dots, no leading zeros), optionally followed by a dash and a short suffix such as -rc1 for a pre-release.';
        }
        foreach ($items as $it) {
            if (strcasecmp((string) ($it['version'] ?? ''), $v) === 0) {
                return "Version $v already exists as a release. Choose a higher number.";
            }
        }
        $latest = self::latest($items);
        if ($latest !== null && version_compare($v, $latest) <= 0) {
            return "Version $v is not higher than the latest known release ($latest). Choose a higher number.";
        }

        return null;
    }

    // ------------------------------------------------------------------ the build

    /** @return array<string,mixed>|null */
    private function load(): ?array
    {
        $d = json_decode((string) ($this->feed->stateGet('build') ?? ''), true);
        if (!is_array($d) || ($d['repo'] ?? '') !== $this->feed->repo() || !isset($d['version']) || !self::validVersion((string) $d['version'])) {
            return null;
        }

        return $d;
    }

    /** @param array<string,mixed> $b */
    private function save(array $b): void
    {
        $this->feed->statePut('build', (string) json_encode($b));
    }

    /** Whether a page should keep polling: running, or finished but the release (or its automatic publish) is still to come, for 30 minutes at most. */
    private static function watch(?array $b): bool
    {
        if ($b === null) {
            return false;
        }
        if (self::active($b)) {
            return true;
        }

        return ($b['state'] ?? '') === 'finished' && time() - (int) ($b['finished_at'] ?? 0) < 1800 && (!$b['release_seen'] || ($b['auto'] && !$b['published']));
    }

    private static function active(?array $b): bool
    {
        return $b !== null && !in_array($b['state'] ?? '', self::TERMINAL, true) && time() - (int) ($b['started_at'] ?? 0) < self::ACTIVE_MAX_S;
    }

    /**
     * @param array{auto:bool,ring:string,pct:int,activate:bool,by_id?:int} $opts what to do when the release appears
     * @return array{ok:bool,message:string}
     */
    public function start(string $version, string $by, array $opts): array
    {
        $repo = $this->feed->repo();
        if (!$this->hasToken()) {
            return ['ok' => false, 'message' => 'No GitHub token is stored. Use the steps for starting the build on GitHub, or save a token first.'];
        }
        $version = trim($version);
        if (!self::validVersion($version)) {
            return ['ok' => false, 'message' => (string) self::checkVersion($version, [])];
        }
        $this->feed->refresh(true);
        $cache = $this->feed->cached();
        if ($cache === null) {
            return ['ok' => false, 'message' => 'The release list could not be read, so the version cannot be checked. Press Check for updates first.'];
        }
        $why = self::checkVersion($version, $cache['items']);
        if ($why !== null) {
            return ['ok' => false, 'message' => $why];
        }
        $cur = $this->load();
        if (self::active($cur)) {
            return ['ok' => false, 'message' => 'A build of ' . $cur['version'] . ' is still running. Wait for it to finish.'];
        }
        // the newest run that exists now: the run this dispatch creates has a higher id
        $before = 0;
        $r0 = $this->call($this->repoPath() . '/actions/workflows/' . self::WORKFLOW . '/runs?event=workflow_dispatch&per_page=5');
        if ($r0['ok']) {
            foreach ((array) (json_decode((string) $r0['body'], true)['workflow_runs'] ?? []) as $run) {
                $before = max($before, (int) ($run['id'] ?? 0));
            }
        } elseif (in_array((int) $r0['status'], [401, 403, 404], true)) {
            return ['ok' => false, 'message' => self::explain($r0, $repo, 'read the workflow runs')];
        }
        $now = time();
        $r = $this->call($this->repoPath() . '/actions/workflows/' . self::WORKFLOW . '/dispatches', ['ref' => self::REF, 'inputs' => ['version' => $version]]);
        if (!$r['ok'] || (int) $r['status'] !== 204 && (int) $r['status'] !== 200) {
            ($this->audit)('Agent Build Started', "$by tried to start a build of agent $version in $repo: failed (HTTP " . (int) $r['status'] . ')');

            return ['ok' => false, 'message' => 'The build was not started. ' . self::explain($r, $repo, 'start the workflow')];
        }
        $ring = in_array($opts['ring'], ['pilot', 'stable'], true) ? $opts['ring'] : '';
        $this->save([
            'repo' => $repo, 'version' => $version, 'tag' => "agent-v$version", 'state' => 'queued', 'detail' => 'waiting for GitHub to start the run', 'run_id' => 0, 'before_id' => $before,
            'started_at' => $now, 'checked_at' => 0, 'by' => mb_substr($by, 0, 80), 'by_id' => (int) ($opts['by_id'] ?? 0), 'auto' => (bool) $opts['auto'], 'ring' => $ring, 'pct' => max(0, min(100, (int) $opts['pct'])),
            'activate' => (bool) $opts['activate'], 'release_seen' => false, 'published' => false, 'publish_message' => '',
        ]);
        ($this->audit)('Agent Build Started', "$by started a build of agent $version in $repo" . ($opts['auto'] ? ' (publish automatically, ring ' . ($ring !== '' ? $ring : 'none') . ')' : ''));

        return ['ok' => true, 'message' => "The build of agent $version was started on GitHub. This card follows it."];
    }

    /** For the cron job: who started the build while there is something to follow (a running build, or a finished one still to publish), else null. @return array{by_id:int,by:string}|null */
    public function pending(): ?array
    {
        $b = $this->load();

        return $b !== null && self::watch($b) ? ['by_id' => (int) ($b['by_id'] ?? 0), 'by' => (string) $b['by']] : null;
    }

    /** Whether a person can still tell the build apart from "nothing happened": the display data for the card and the poll. @return array<string,mixed>|null */
    public function display(): ?array
    {
        $b = $this->load();
        if ($b === null) {
            return null;
        }
        $repo = $this->feed->repo();
        $labels = ['queued' => 'Queued', 'building' => 'Building', 'finished' => 'Finished', 'failed' => 'Failed', 'lost' => 'No run found'];

        return [
            'version' => (string) $b['version'], 'tag' => (string) $b['tag'], 'state' => (string) $b['state'], 'label' => $labels[$b['state']] ?? 'Unknown', 'detail' => (string) ($b['detail'] ?? ''),
            'run_url' => (int) $b['run_id'] > 0 ? "https://github.com/$repo/actions/runs/" . (int) $b['run_id'] : '', 'workflow_url' => "https://github.com/$repo/actions/workflows/" . self::WORKFLOW,
            'started_at' => (int) $b['started_at'], 'by' => (string) $b['by'], 'auto' => (bool) $b['auto'], 'ring' => (string) $b['ring'], 'pct' => (int) $b['pct'], 'release_seen' => (bool) $b['release_seen'],
            'published' => (bool) $b['published'], 'publish_message' => (string) ($b['publish_message'] ?? ''), 'active' => self::active($b), 'watch' => self::watch($b),
            'steps' => self::cleanSteps($b['steps'] ?? [], $b['state'] === 'finished'), 'est_pct' => $b['state'] === 'finished' ? 100 : max(0, min(95, (int) ($b['est_pct'] ?? 0))),
        ];
    }

    /**
     * The workflow job's steps as a done/active/pending checklist (names are GitHub's, shown as text only). GitHub's own bookkeeping steps (set up, post, complete) are left out.
     *
     * @return list<array{name:string,status:string}>
     */
    private static function cleanSteps(mixed $raw, bool $allDone = false): array
    {
        $out = [];
        foreach (is_array($raw) ? array_slice($raw, 0, 30) : [] as $st) {
            $n = is_array($st) && is_string($st['name'] ?? null) ? trim((string) preg_replace('/[^\x20-\x7e]/', '', $st['name'])) : '';
            $s = is_array($st) && in_array($st['status'] ?? null, ['done', 'active', 'pending', 'failed'], true) ? $st['status'] : 'pending';
            if ($n !== '') {
                $out[] = ['name' => substr($n, 0, 80), 'status' => $allDone && $s !== 'failed' ? 'done' : $s];
            }
        }

        return $out;
    }

    /** @return array{0:list<array{name:string,status:string}>,1:int} the steps of the run's job and a percent estimate (finished steps / all steps) */
    private function runSteps(int $runId): array
    {
        $r = $this->call($this->repoPath() . '/actions/runs/' . $runId . '/jobs?per_page=10');
        if (!$r['ok']) {
            return [[], 0];
        }
        $steps = [];
        foreach ((array) (json_decode((string) $r['body'], true)['jobs'] ?? []) as $job) {
            foreach ((array) ($job['steps'] ?? []) as $st) {
                $name = is_string($st['name'] ?? null) ? $st['name'] : '';
                if ($name === '' || preg_match('/^(Set up job|Complete job|Post )/', $name) === 1) {
                    continue;
                }
                $status = (string) ($st['status'] ?? '');
                $steps[] = ['name' => $name, 'status' => $status === 'completed' ? (in_array($st['conclusion'] ?? '', ['failure', 'cancelled'], true) ? 'failed' : 'done') : ($status === 'in_progress' ? 'active' : 'pending')];
            }
        }
        $steps = self::cleanSteps($steps);
        $done = count(array_filter($steps, static fn (array $x): bool => $x['status'] === 'done'));

        return [$steps, $steps === [] ? 0 : (int) floor(95 * $done / count($steps))];
    }

    /**
     * Ask GitHub where the build is (at most one run lookup per POLL_MIN_S) and move the stored state on. When the run is done and the release is in the list and the build was
     * started with "publish automatically", $publisher(tag, ['ring','pct','activate']) is called once (a database lock makes two parallel polls publish once).
     *
     * @param (callable(string,array<string,mixed>):array{ok:bool,message:string})|null $publisher
     * @return array<string,mixed>|null display()
     */
    public function poll(?callable $publisher = null): ?array
    {
        $b = $this->load();
        if ($b === null) {
            return null;
        }
        $now = time();
        $running = self::active($b) && in_array($b["state"], ["queued", "building"], true);
        if (($running || self::watch($b)) && $now - (int) $b['checked_at'] >= self::POLL_MIN_S) {
            $b['checked_at'] = $now;
            $repo = $this->feed->repo();
            if ($b['state'] === 'queued' || $b['state'] === 'building') {
                if ((int) $b['run_id'] === 0) {
                    $r = $this->call($this->repoPath() . '/actions/workflows/' . self::WORKFLOW . '/runs?event=workflow_dispatch&per_page=10');
                    if ($r['ok']) {
                        $found = 0;
                        foreach ((array) (json_decode((string) $r['body'], true)['workflow_runs'] ?? []) as $run) {
                            $id = (int) ($run['id'] ?? 0);
                            if ($id > (int) $b['before_id'] && ($found === 0 || $id < $found)) {
                                $found = $id;   // the earliest run that did not exist before the dispatch
                            }
                        }
                        if ($found > 0) {
                            $b['run_id'] = $found;
                        } elseif ($now - (int) $b['started_at'] > self::RUN_SEARCH_S) {
                            $b['state'] = 'lost';
                            $b['detail'] = 'GitHub accepted the request but no run showed up. Look at the workflow page on GitHub.';
                            ($this->audit)('Agent Build Finished', 'build of agent ' . $b['version'] . ' in ' . $repo . ': no run found');
                        }
                    } else {
                        $b['detail'] = self::explain($r, $repo, 'read the workflow runs');
                    }
                }
                if ((int) $b['run_id'] > 0 && in_array($b['state'], ['queued', 'building'], true)) {
                    $r = $this->call($this->repoPath() . '/actions/runs/' . (int) $b['run_id']);
                    if ($r['ok']) {
                        $d = json_decode((string) $r['body'], true);
                        $st = is_array($d) ? (string) ($d['status'] ?? '') : '';
                        $co = is_array($d) ? (string) ($d['conclusion'] ?? '') : '';
                        if ($st === 'completed') {
                            if ($co === 'success') {
                                $b['state'] = 'finished';
                                $b['finished_at'] = $now;
                                $b['detail'] = 'The workflow finished. Waiting for the release to appear in the list.';
                            } else {
                                $b['state'] = 'failed';
                                [$b['steps'], $b['est_pct']] = $this->runSteps((int) $b['run_id']);
                                $b['detail'] = 'The workflow ended with: ' . (preg_replace('/[^a-z_]/', '', $co) ?: 'unknown') . '. Open the run on GitHub to see why.';
                            }
                            ($this->audit)('Agent Build Finished', 'build of agent ' . $b['version'] . ' in ' . $repo . ': ' . $b['state'] . ($co !== '' ? ' (' . preg_replace('/[^a-z_]/', '', $co) . ')' : ''));
                        } elseif ($st === 'in_progress') {
                            $b['state'] = 'building';
                            $b['detail'] = 'GitHub is building the agents and the installer package.';
                            [$b['steps'], $b['est_pct']] = $this->runSteps((int) $b['run_id']);
                        } else {
                            $b['state'] = 'queued';
                            $b['detail'] = 'The run is waiting for a GitHub runner.';
                        }
                    } else {
                        $b['detail'] = self::explain($r, $repo, 'read the workflow run');
                    }
                }
            }
            if ($b['state'] === 'finished' && !$b['release_seen']) {
                $this->feed->refresh(true);
                $c = $this->feed->cached();
                foreach ($c['items'] ?? [] as $it) {
                    if (($it['tag'] ?? '') === $b['tag']) {
                        $b['release_seen'] = true;
                        $b['detail'] = 'The release is in the list below.';
                    }
                }
            }
            $this->save($b);
        }
        $b = $this->load() ?? $b;
        if ($b['state'] === 'finished' && $b['release_seen'] && $b['auto'] && !$b['published'] && $publisher !== null) {
            $b = $this->autoPublish($b, $publisher);
        }

        return $this->display();
    }

    /** @param array<string,mixed> $b @param callable(string,array<string,mixed>):array{ok:bool,message:string} $publisher @return array<string,mixed> */
    private function autoPublish(array $b, callable $publisher): array
    {
        $got = $this->feed->lock('rivet_agent_build_publish');
        if (!$got) {
            return $b;
        }
        try {
            $b = $this->load() ?? $b;
            if ($b['published']) {
                return $b;
            }
            $b['published'] = true;   // once: a failed automatic publish is not retried in a loop; the Publish button stays
            $res = $publisher((string) $b['tag'], ['ring' => (string) $b['ring'], 'pct' => (int) $b['pct'], 'activate' => (bool) $b['activate']]);
            $b['publish_message'] = mb_substr((string) $res['message'], 0, 400);
            $this->save($b);
        } finally {
            $this->feed->unlock('rivet_agent_build_publish');
        }

        return $b;
    }

    // ------------------------------------------------------------------ what the card shows

    /** @return array<string,mixed> */
    public function view(): array
    {
        $repo = $this->feed->repo();
        $c = $this->feed->cached();
        $latest = $c !== null ? self::latest($c['items']) : null;

        return [
            'repo' => $repo, 'has_token' => $this->hasToken(), 'test' => $this->lastTest(), 'latest' => $latest, 'suggest' => self::suggest($latest), 'build' => $this->display(),
            'workflow_url' => "https://github.com/$repo/actions/workflows/" . self::WORKFLOW,
        ];
    }
}

/**
 * The publisher the automatic "publish when built" uses: the same ability check, size limit, service check and Feed::publish() as the Publish button.
 *
 * @return callable(string,array<string,mixed>):array{ok:bool,message:string}
 */
function rivetAgentBuildPublisher(object $rmm, Feed $feed, object $admin, object $who, int $userId, string $userName): callable
{
    return static function (string $tag, array $o) use ($rmm, $feed, $admin, $who, $userId, $userName): array {
        $denied = $rmm->authorizer()->check($userId, \RivetCore\Rmm\Authz\RmmAbility::BINARY_PUBLISH, 0, false);
        if ($denied !== null) {
            return ['ok' => false, 'message' => 'Not published automatically: ' . $denied];
        }
        @set_time_limit(600);
        $res = $feed->publish($admin, $who, $tag, [
            'ring' => in_array($o['ring'] ?? '', ['pilot', 'stable'], true) ? $o['ring'] : '', 'pct' => (int) ($o['pct'] ?? 10), 'activate' => (bool) ($o['activate'] ?? false),
            'max_bytes' => $rmm->binaryStore()->maxBytes(), 'service_ready' => ($rmm->readModel()->settingsSummary()['service_base'] ?? null) !== null,
        ]);
        logAction('Endpoint Agent', 'Agent Release Downloaded', "$userName's automatic publish of $tag from " . $feed->repo() . ' (ring ' . (($o['ring'] ?? '') !== '' ? $o['ring'] : 'none') . '): ' . ($res['ok'] ? 'ok' : 'not completely') . ' - ' . $res['message']);

        return ['ok' => $res['ok'], 'message' => $res['message']];
    };
}
