<?php

/*
 * Administration > Endpoint agent > Agent updates > "Build on my build host" (26.10.33; protocol: rivet-core docs/rmm/BUILD_HOST_PROTOCOL.md).
 *
 * The SERVER half. A build host is a machine the owner controls that runs endpoint-agent/scripts/build-host.sh from cron or a timer; it only ever makes outbound requests:
 * poll (is there a job?), status (building / uploading / failed + log tail), upload (one chunk of one file of the job) and complete (here is the manifest).
 *
 * Trust model:
 *  - A build host token ("rbh_<16 hex selector>_<48 hex secret>") authenticates the four calls ONLY and nothing else. The database holds the selector (to find the row), a per-token
 *    random salt and sha256(salt . secret); compared with hash_equals(). Shown once. Revocable (disable, delete, rotate).
 *  - Nothing the host sends is trusted: file names come from a fixed list, storage names are random (no path is ever built from host input), every chunk is bounded and hashed,
 *    the assembled files are hashed again and compared with the manifest AND SHA256SUMS, then the EXISTING validators run (BinaryInspector for the PE and its architecture,
 *    MsiInspector for the MSI), and only then does the EXISTING publish code (RmmAdmin::uploadBinary / uploadMsiTemplate, which authorize and audit) store anything.
 *    The server never executes, unpacks or interprets an uploaded file. Display text (note, log tail, host name) is escaped where it is printed.
 *  - Files stay in a per-job quarantine folder (backups/endpoint-build/<job id>/, private to the web user, outside the web root) until the job is published, failed, cancelled or old.
 */

namespace RivetMsp\AgentFeed;

require_once __DIR__ . '/rmm_agent_build.php';
require_once __DIR__ . '/rmm_linux_store.php';

final class LocalBuild
{
    public const FILES = ['rivetit-agent-windows-amd64.exe' => 'amd64', 'rivetit-agent-windows-arm64.exe' => 'arm64', 'rivetit-agent-windows-amd64.msi' => 'amd64', 'SHA256SUMS' => ''];
    /**
     * Additive (hosted Linux agent files, ported from RivetIT): a newer build host also sends these, all optional. An older build host that never sends
     * them does not fail the job — the Windows files above are still required and verified exactly as before. Hosted by LinuxStore (rmm_linux_store.php);
     * never validated with BinaryInspector/MsiInspector (those are PE/MSI-specific) — LinuxStore::validate() does the ELF/script/gzip checks instead.
     */
    public const LINUX_FILES = ['rivetit-agent-linux-amd64', 'rivetit-agent-linux-arm64', 'install-linux.sh', 'rivetit-agent-linux-amd64.tar.gz', 'rivetit-agent-linux-arm64.tar.gz'];
    public const TARGETS = ['windows-amd64', 'windows-arm64', 'linux-amd64', 'linux-arm64'];
    public const CHUNK_MAX = 1048576;           // raw bytes of one chunk
    public const SUMS_MAX = 16384;              // SHA256SUMS
    public const JOB_CAP_FACTOR = 3;            // per-job cap = 3 x the binary cap (two exe + the MSI) + the sums file
    public const LEASE_S = 900;                 // a claimed job nobody started within 15 minutes goes back to the queue; status / upload renew it
    public const EXPIRE_S = 3600;               // an active job without any status for 60 minutes expires
    public const KEEP_BUILT_S = 604800;         // built-but-not-published files are kept 7 days
    public const BODY_MAX = 1800000;            // a request body: 1 MiB raw as base64 plus the JSON around it
    public const TOKEN_RE = '/^rbh_([0-9a-f]{16})_([0-9a-f]{48})\z/';
    public const REF_RE = '/^(?:main|(?:agent-)?v[0-9]{1,5}(?:\.[0-9]{1,5}){1,3}(?:-[0-9A-Za-z.]{1,18})?)\z/';
    /** The stages a build host may report, in order, with the label the page shows (progress is strictly allowlisted: anything else is refused). */
    public const STAGES = ['prepare' => 'Preparing the build', 'clone' => 'Fetching the source', 'build_windows_amd64' => 'Building Windows x64', 'build_windows_arm64' => 'Building Windows ARM64',
        'msi' => 'Packaging the MSI', 'verify' => 'Verifying the files', 'upload' => 'Uploading to this server'];
    public const DETAIL_RE = '/^[\x20-\x7e]{0,120}\z/';
    public const HOST_NAME_RE = '/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,78}\z/u';

    /** @var callable(string,string):void */
    private $audit;

    /** @param callable(string,string):void|null $audit (action, text) */
    public function __construct(private readonly \mysqli $db, private readonly string $dir, private readonly int $maxBytes = 67108864, ?callable $audit = null)
    {
        $this->audit = $audit ?? static function (string $a, string $t): void {
        };
    }

    public function ready(): bool
    {
        foreach (['endpoint_agent_build_hosts', 'endpoint_agent_build_jobs', 'endpoint_agent_build_chunks'] as $t) {
            $r = @mysqli_query($this->db, "SHOW TABLES LIKE '$t'");
            if (!$r || mysqli_num_rows($r) === 0) {
                return false;
            }
        }
        $r = @mysqli_query($this->db, "SHOW COLUMNS FROM endpoint_agent_build_jobs LIKE 'progress_at'");   // DB 2.6.100
        if (!$r || mysqli_num_rows($r) === 0) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ small helpers

    /** @param list<string|int|null> $args */
    private function bind(\mysqli_stmt $st, array $args): void
    {
        if ($args !== []) {
            $types = '';
            foreach ($args as $a) {
                $types .= is_int($a) ? 'i' : 's';
            }
            mysqli_stmt_bind_param($st, $types, ...$args);
        }
    }

    /** @param list<string|int|null> $args @return list<array<string,mixed>> */
    private function rows(string $sql, array $args = []): array
    {
        $st = mysqli_prepare($this->db, $sql);
        if ($st === false) {
            return [];
        }
        $this->bind($st, $args);
        $out = [];
        if (mysqli_stmt_execute($st)) {
            $res = mysqli_stmt_get_result($st);
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $out[] = $row;
            }
        }
        mysqli_stmt_close($st);

        return $out;
    }

    /** @param list<string|int|null> $args @return int affected rows, -1 on error */
    private function run(string $sql, array $args = []): int
    {
        $st = mysqli_prepare($this->db, $sql);
        if ($st === false) {
            return -1;
        }
        $this->bind($st, $args);
        $n = mysqli_stmt_execute($st) ? mysqli_stmt_affected_rows($st) : -1;
        mysqli_stmt_close($st);

        return $n;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function say(string $action, string $text): void
    {
        ($this->audit)($action, $text);
    }

    /** Printable, bounded text from a host: control characters dropped, anything that looks like a token masked. */
    public static function cleanText(string $s, int $max): string
    {
        $s = preg_replace('/[^\x09\x0A\x20-\x7e\x{00a0}-\x{ffff}]/u', '', $s) ?? '';

        return mb_substr(self::mask($s), 0, $max);
    }

    /** Defence in depth: whatever looks like one of our tokens or a GitHub token never reaches the database or the page. */
    public static function mask(string $s): string
    {
        return (string) preg_replace(['/rbh_[0-9a-f]{16}_[0-9a-f]{48}/', '/(?:github_pat_|ghp_|gho_|ghs_)[A-Za-z0-9_]{16,}/', '/(Bearer\s+)[A-Za-z0-9._~+\/=-]{12,}/i'], ['rbh_[masked]', '[masked]', '$1[masked]'], $s);
    }

    // ------------------------------------------------------------------ hosts (administrator side)

    /** @return array{ok:bool,message:string,token?:string,host_id?:int} the token is returned ONCE */
    public function addHost(string $name, int $userId): array
    {
        $name = trim($name);
        if (preg_match(self::HOST_NAME_RE, $name) !== 1) {
            return ['ok' => false, 'message' => 'Give the build host a short name (letters, numbers, spaces, dots, dashes), for example "build-vm-1".'];
        }
        if ((int) ($this->rows('SELECT COUNT(*) AS c FROM endpoint_agent_build_hosts')[0]['c'] ?? 0) >= 20) {
            return ['ok' => false, 'message' => 'There are already 20 build hosts. Delete one first.'];
        }
        if ($this->rows('SELECT host_id FROM endpoint_agent_build_hosts WHERE host_name = ?', [$name]) !== []) {
            return ['ok' => false, 'message' => 'A build host with that name already exists.'];
        }
        [$token, $sel, $salt, $hash] = $this->newToken();
        $n = $this->run('INSERT INTO endpoint_agent_build_hosts (host_name, token_selector, token_salt, token_hash, enabled, created_at, created_by) VALUES (?, ?, ?, ?, 1, ?, ?)', [$name, $sel, $salt, $hash, self::now(), $userId]);
        if ($n !== 1) {
            return ['ok' => false, 'message' => 'The build host could not be saved.'];
        }
        $id = (int) mysqli_insert_id($this->db);
        $this->say('Agent Build Host Added', "build host \"$name\" (#$id) was added");

        return ['ok' => true, 'message' => "Build host \"$name\" was added.", 'token' => $token, 'host_id' => $id];
    }

    /** @return array{0:string,1:string,2:string,3:string} token, selector, salt, hash */
    private function newToken(): array
    {
        $sel = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        $salt = bin2hex(random_bytes(16));

        return ["rbh_{$sel}_{$secret}", $sel, $salt, hash('sha256', $salt . $secret)];
    }

    /** @return array{ok:bool,message:string,token?:string} */
    public function rotate(int $hostId): array
    {
        $h = $this->host($hostId);
        if ($h === null) {
            return ['ok' => false, 'message' => 'That build host does not exist.'];
        }
        [$token, $sel, $salt, $hash] = $this->newToken();
        $this->run('UPDATE endpoint_agent_build_hosts SET token_selector = ?, token_salt = ?, token_hash = ?, rotated_at = ? WHERE host_id = ?', [$sel, $salt, $hash, self::now(), $hostId]);
        $this->say('Agent Build Host Token Rotated', 'the token of build host "' . $h['host_name'] . "\" (#$hostId) was replaced; the old one stopped working");

        return ['ok' => true, 'message' => 'A new token was created for "' . $h['host_name'] . '". The old token no longer works.', 'token' => $token];
    }

    /** @return array{ok:bool,message:string} */
    public function setEnabled(int $hostId, bool $on): array
    {
        $h = $this->host($hostId);
        if ($h === null) {
            return ['ok' => false, 'message' => 'That build host does not exist.'];
        }
        $this->run('UPDATE endpoint_agent_build_hosts SET enabled = ? WHERE host_id = ?', [$on ? 1 : 0, $hostId]);
        $this->say($on ? 'Agent Build Host Enabled' : 'Agent Build Host Disabled', 'build host "' . $h['host_name'] . "\" (#$hostId) was " . ($on ? 'enabled' : 'disabled'));

        return ['ok' => true, 'message' => 'Build host "' . $h['host_name'] . '" ' . ($on ? 'enabled.' : 'disabled. Its token is refused until you enable it again.')];
    }

    /** @return array{ok:bool,message:string} */
    public function deleteHost(int $hostId): array
    {
        $h = $this->host($hostId);
        if ($h === null) {
            return ['ok' => false, 'message' => 'That build host does not exist.'];
        }
        foreach ($this->rows('SELECT job_id FROM endpoint_agent_build_jobs WHERE host_id = ? AND state IN (\'claimed\',\'building\',\'uploading\')', [$hostId]) as $j) {
            $this->finish((int) $j['job_id'], 'failed', 'The build host was deleted while it held this job.');
        }
        $this->run('UPDATE endpoint_agent_build_jobs SET host_id = NULL WHERE host_id = ?', [$hostId]);
        $this->run('DELETE FROM endpoint_agent_build_hosts WHERE host_id = ?', [$hostId]);
        $this->say('Agent Build Host Deleted', 'build host "' . $h['host_name'] . "\" (#$hostId) was deleted");

        return ['ok' => true, 'message' => 'Build host "' . $h['host_name'] . '" was deleted.'];
    }

    /** @return array<string,mixed>|null */
    public function host(int $hostId): ?array
    {
        return $this->rows('SELECT * FROM endpoint_agent_build_hosts WHERE host_id = ?', [$hostId])[0] ?? null;
    }

    /** @return list<array<string,mixed>> never contains the hash or salt */
    public function hosts(): array
    {
        $out = [];
        foreach ($this->rows('SELECT host_id, host_name, enabled, created_at, rotated_at, last_seen_at, last_ip, last_version, last_caps FROM endpoint_agent_build_hosts ORDER BY host_name') as $r) {
            $seen = $r['last_seen_at'] !== null ? (int) strtotime($r['last_seen_at'] . ' UTC') : 0;
            $r['seen_ago_s'] = $seen > 0 ? max(0, time() - $seen) : null;
            $r['dot'] = (int) $r['enabled'] !== 1 ? 'off' : ($seen === 0 ? 'never' : (time() - $seen < 300 ? 'ok' : (time() - $seen < 3600 ? 'late' : 'down')));
            $out[] = $r;
        }

        return $out;
    }

    // ------------------------------------------------------------------ the host's token

    /** @return array<string,mixed>|null the host row, or null (also for a disabled host); the same work is done whether or not the selector exists */
    public function authenticate(string $authorization): ?array
    {
        if (preg_match('/^Bearer (\S{1,200})\z/', $authorization, $m) !== 1 || preg_match(self::TOKEN_RE, $m[1], $t) !== 1) {
            return null;
        }
        $row = $this->rows('SELECT * FROM endpoint_agent_build_hosts WHERE token_selector = ?', [$t[1]])[0] ?? null;
        $salt = $row !== null ? (string) $row['token_salt'] : str_repeat('0', 32);
        $want = $row !== null ? (string) $row['token_hash'] : str_repeat('0', 64);
        $ok = hash_equals($want, hash('sha256', $salt . $t[2]));

        return $row !== null && $ok && (int) $row['enabled'] === 1 ? $row : null;
    }

    public function touch(int $hostId, string $ip, ?string $version = null, ?string $caps = null): void
    {
        $this->run('UPDATE endpoint_agent_build_hosts SET last_seen_at = ?, last_ip = ?, last_version = COALESCE(?, last_version), last_caps = COALESCE(?, last_caps) WHERE host_id = ?',
            [self::now(), mb_substr($ip, 0, 45), $version === null ? null : self::cleanText($version, 40), $caps === null ? null : mb_substr($caps, 0, 80), $hostId]);
    }

    // ------------------------------------------------------------------ requests (administrator side)

    /**
     * @param array{auto:bool,ring:string,pct:int,activate:bool,msi:bool,ref:string} $o
     * @param list<array<string,mixed>> $feedItems Feed::cached()['items'] (the GitHub list)
     * @return array{ok:bool,message:string,job_id?:int}
     */
    public function request(string $version, array $o, int $userId, string $userName, array $feedItems, ?string $githubActiveVersion, ?string $highestHosted): array
    {
        $version = trim($version);
        $why = Build::checkVersion($version, $feedItems);
        if ($why !== null) {
            return ['ok' => false, 'message' => $why];
        }
        if ($highestHosted !== null && Build::validVersion($highestHosted) && version_compare($version, $highestHosted) <= 0) {
            return ['ok' => false, 'message' => "Version $version is not higher than the version already hosted here ($highestHosted). Choose a higher number."];
        }
        $ref = (string) $o['ref'];
        if (preg_match(self::REF_RE, $ref) !== 1) {
            return ['ok' => false, 'message' => 'That source reference is not allowed. Use main, or a tag such as v1.2.3 or agent-v1.2.3.'];
        }
        $ring = in_array($o['ring'], ['pilot', 'stable'], true) ? $o['ring'] : '';
        if ($githubActiveVersion !== null && strcasecmp($githubActiveVersion, $version) === 0) {
            return ['ok' => false, 'message' => "A build of $version is already running on GitHub."];
        }
        $this->sweep();
        $open = $this->rows("SELECT job_id, version, state FROM endpoint_agent_build_jobs WHERE state IN ('queued','claimed','building','uploading','verifying') ORDER BY job_id");
        foreach ($open as $j) {
            if (strcasecmp((string) $j['version'], $version) === 0) {
                return ['ok' => false, 'message' => "A build of $version is already in progress (job #" . (int) $j['job_id'] . ').'];
            }
        }
        if (count($open) >= 3) {
            return ['ok' => false, 'message' => 'Three builds are already waiting or running. Cancel one or wait.'];
        }
        if ($this->rows("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = ? AND state IN ('built','published')", [$version]) !== []) {
            return ['ok' => false, 'message' => "Version $version was already built on a build host. Publish that build or choose a higher number."];
        }
        $now = self::now();
        $n = $this->run('INSERT INTO endpoint_agent_build_jobs (version, source_ref, want_msi, state, auto_publish, ring, rollout_pct, activate, requested_by, requested_by_name, created_at, updated_at) VALUES (?, ?, ?, \'queued\', ?, ?, ?, ?, ?, ?, ?, ?)',
            [$version, $ref, $o['msi'] ? 1 : 0, $o['auto'] ? 1 : 0, $ring, max(0, min(100, (int) $o['pct'])), $o['activate'] ? 1 : 0, $userId, mb_substr($userName, 0, 80), $now, $now]);
        if ($n !== 1) {
            return ['ok' => false, 'message' => 'The build request could not be saved.'];
        }
        $id = (int) mysqli_insert_id($this->db);
        $this->say('Agent Build Started', "$userName requested a build of agent $version from ref $ref on a build host (job #$id" . ($o['auto'] ? ', publish automatically, ring ' . ($ring !== '' ? $ring : 'none') : '') . ')');

        return ['ok' => true, 'message' => "The build of agent $version is queued. The next time a build host asks (within a minute) it starts.", 'job_id' => $id];
    }

    /** @return array{ok:bool,message:string} */
    public function cancel(int $jobId, string $by): array
    {
        $j = $this->job($jobId);
        if ($j === null) {
            return ['ok' => false, 'message' => 'That build does not exist.'];
        }
        $now = self::now();
        $n = $this->run("UPDATE endpoint_agent_build_jobs SET state = 'cancelled', note = 'Cancelled by an administrator.', finished_at = ?, updated_at = ? WHERE job_id = ? AND state IN ('queued','claimed','building','uploading','built')", [$now, $now, $jobId]);
        if ($n !== 1) {
            return ['ok' => false, 'message' => 'That build can no longer be cancelled (it is ' . $j['state'] . ').'];
        }
        $this->purge($jobId);
        $this->say('Agent Build Cancelled', "$by cancelled the build of agent " . $j['version'] . " (job #$jobId)");

        return ['ok' => true, 'message' => 'The build of ' . $j['version'] . ' was cancelled.'];
    }

    /** @return array<string,mixed>|null */
    public function job(int $jobId): ?array
    {
        return $this->rows('SELECT * FROM endpoint_agent_build_jobs WHERE job_id = ?', [$jobId])[0] ?? null;
    }

    /** @return list<array<string,mixed>> the display rows for the page (no manifest; the renderer escapes) */
    public function jobs(int $limit = 8): array
    {
        $this->sweep();
        $out = [];
        foreach ($this->rows('SELECT j.job_id, j.version, j.source_ref, j.state, j.note, j.log_tail, j.result, j.auto_publish, j.ring, j.rollout_pct, j.activate, j.want_msi, j.progress_stage, j.progress_pct, j.progress_detail, j.progress_at, j.requested_by_name, j.created_at, j.updated_at, j.finished_at, h.host_name
            FROM endpoint_agent_build_jobs j LEFT JOIN endpoint_agent_build_hosts h ON h.host_id = j.host_id ORDER BY j.job_id DESC LIMIT ' . max(1, min(50, $limit))) as $r) {
            $r['idle_s'] = max(0, time() - (int) strtotime($r['updated_at'] . ' UTC'));
            $r['active'] = in_array($r['state'], ['queued', 'claimed', 'building', 'uploading', 'verifying'], true);
            $r['progress'] = $this->progress((int) $r['job_id'], (string) $r['state'], $r);
            // A built job has no finish time until it is published: its clock stops at the last report, it must not run on.
            $end = $r['finished_at'] !== null ? (int) strtotime($r['finished_at'] . ' UTC') : ($r['state'] === 'built' ? (int) strtotime($r['updated_at'] . ' UTC') : time());
            $r['elapsed_s'] = max(0, $end - (int) strtotime($r['created_at'] . ' UTC'));
            $out[] = $r;
        }

        return $out;
    }

    /**
     * The progress the page draws. When the build host reports stages (build host protocol 1.1) the bar is its percent and the checklist its stages; otherwise the coarse state-based
     * estimate below is used and the checklist is empty.
     *
     * @param array<string,mixed> $row @return array{pct:int,text:string,real:bool,stage:string,detail:string,steps:list<array{key:string,label:string,status:string}>}
     */
    private function progress(int $jobId, string $state, array $row = []): array
    {
        $coarse = $this->coarseProgress($jobId, $state);
        $stage = (string) ($row['progress_stage'] ?? '');
        if (!isset(self::STAGES[$stage]) && !in_array($state, ['built', 'published'], true)) {
            return $coarse + ['real' => false, 'stage' => '', 'detail' => '', 'steps' => []];
        }
        $keys = array_keys(self::STAGES);
        if ((int) ($row['want_msi'] ?? 1) !== 1) {
            $keys = array_values(array_diff($keys, ['msi']));
        }
        $done = in_array($state, ['built', 'published'], true);
        $cur = $done ? count($keys) : (int) array_search($stage, $keys, true);
        if (!$done && !in_array($stage, $keys, true)) {
            $cur = 0;
        }
        $steps = [];
        foreach ($keys as $i => $k) {
            $st = $i < $cur ? 'done' : ($i === $cur ? 'active' : 'pending');
            if ($st === 'active' && in_array($state, ['failed', 'cancelled', 'expired'], true)) {
                $st = 'failed';
            }
            $steps[] = ['key' => $k, 'label' => self::STAGES[$k], 'status' => $st];
        }
        $pct = $done ? 100 : max(0, min(99, (int) ($row['progress_pct'] ?? 0)));

        return ['pct' => $pct, 'text' => $done ? $coarse['text'] : self::STAGES[$stage], 'real' => true, 'stage' => $stage, 'detail' => $done ? '' : (string) ($row['progress_detail'] ?? ''), 'steps' => $steps];
    }

    /** @return array{pct:int,text:string} */
    private function coarseProgress(int $jobId, string $state): array
    {
        $steps = ['queued' => [0, 'waiting for a build host'], 'claimed' => [10, 'a build host took the job'], 'building' => [35, 'building'], 'uploading' => [65, 'uploading'], 'verifying' => [85, 'verifying'], 'built' => [95, 'built, ready to publish'], 'published' => [100, 'published']];
        [$p, $t] = $steps[$state] ?? [0, $state];
        if ($state === 'uploading') {
            $c = (int) ($this->rows('SELECT COUNT(*) AS c FROM endpoint_agent_build_chunks WHERE job_id = ?', [$jobId])[0]['c'] ?? 0);
            $tot = (int) ($this->rows('SELECT COALESCE(SUM(t), 0) AS s FROM (SELECT MAX(chunk_total) AS t FROM endpoint_agent_build_chunks WHERE job_id = ? GROUP BY file_name) x', [$jobId])[0]['s'] ?? 0);
            if ($tot > 0) {
                $p = 55 + (int) round(25 * min(1, $c / $tot));
                $t = "uploading ($c of $tot chunks so far)";
            }
        }

        return ['pct' => $p, 'text' => $t];
    }

    public function hasActive(): bool
    {
        return (int) ($this->rows("SELECT COUNT(*) AS c FROM endpoint_agent_build_jobs WHERE state IN ('queued','claimed','building','uploading','verifying')")[0]['c'] ?? 0) > 0;
    }

    // ------------------------------------------------------------------ housekeeping

    /** Expire what has gone quiet; return an unstarted claim to the queue. Cheap; called by every host call and every page view. */
    public function sweep(): void
    {
        $now = self::now();
        $this->run("UPDATE endpoint_agent_build_jobs SET state = 'queued', host_id = NULL, lease_expires_at = NULL, progress_stage = '', progress_pct = 0, progress_detail = '', progress_at = NULL, updated_at = ? WHERE state = 'claimed' AND lease_expires_at < ?", [$now, $now]);
        $cut = gmdate('Y-m-d H:i:s', time() - self::EXPIRE_S);
        foreach ($this->rows("SELECT job_id, version, state FROM endpoint_agent_build_jobs WHERE state IN ('queued','building','uploading','verifying') AND updated_at < ?", [$cut]) as $j) {
            if ($this->run("UPDATE endpoint_agent_build_jobs SET state = 'expired', note = 'No status for 60 minutes; the job expired.', finished_at = ?, updated_at = ? WHERE job_id = ? AND state = ?", [$now, $now, (int) $j['job_id'], $j['state']]) === 1) {
                $this->purge((int) $j['job_id']);
                $this->say('Agent Build Finished', 'build of agent ' . $j['version'] . ' (job #' . (int) $j['job_id'] . '): expired');
            }
        }
        foreach ($this->rows("SELECT job_id FROM endpoint_agent_build_jobs WHERE state = 'built' AND updated_at < ?", [gmdate('Y-m-d H:i:s', time() - self::KEEP_BUILT_S)]) as $j) {
            $this->finish((int) $j['job_id'], 'expired', 'Built but not published within 7 days; the files were removed.');
        }
    }

    private function finish(int $jobId, string $state, string $note): void
    {
        $now = self::now();
        $this->run('UPDATE endpoint_agent_build_jobs SET state = ?, note = ?, finished_at = ?, updated_at = ? WHERE job_id = ?', [$state, mb_substr($note, 0, 500), $now, $now, $jobId]);
        $this->purge($jobId);
    }

    /** Delete the job's quarantine folder and its chunk rows. */
    private function purge(int $jobId): void
    {
        $d = $this->jobDir($jobId, false);
        if ($d !== null && is_dir($d)) {
            foreach (glob($d . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($d);
        }
        $this->run('DELETE FROM endpoint_agent_build_chunks WHERE job_id = ?', [$jobId]);
    }

    /** The folder of one job (only ever "<dir>/<int>"); created 0700 with a deny file when $create. */
    private function jobDir(int $jobId, bool $create): ?string
    {
        if ($jobId < 1 || $this->dir === '') {
            return null;
        }
        if ($create && !is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        if ($create && is_dir($this->dir) && !is_file($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\nOptions -Indexes -ExecCGI\n");
            @file_put_contents($this->dir . '/index.html', '');
        }
        $d = rtrim($this->dir, '/') . '/' . $jobId;
        if ($create && !is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            return null;
        }

        return $d;
    }

    // ------------------------------------------------------------------ the host's four calls
    // Each returns [http status, body array]. $host is the authenticated row.

    /** @param array<string,mixed> $host @param array<string,mixed> $in @return array{0:int,1:array<string,mixed>} */
    public function poll(array $host, array $in, string $ip = ''): array
    {
        $this->sweep();
        $hostId = (int) $host['host_id'];
        $caps = is_array($in['capabilities'] ?? null) ? $in['capabilities'] : [];
        $docker = ($caps['docker'] ?? false) === true;
        $msi = ($caps['msi'] ?? false) === true;
        $this->touch($hostId, $ip, is_string($in['host_version'] ?? null) ? $in['host_version'] : '', ($docker ? 'docker' : '') . ($msi ? ' msi' : ''));
        if (!array_key_exists('capabilities', $in)) {
            return [200, ['job' => null]];   // `build-host.sh --register`: records last seen only, never claims
        }
        $mine = $this->rows("SELECT * FROM endpoint_agent_build_jobs WHERE host_id = ? AND state IN ('claimed','building','uploading','verifying') ORDER BY job_id LIMIT 1", [$hostId])[0] ?? null;
        if ($mine !== null) {
            // one job per host: a claimed job is handed out again (a lost answer); a running one is not followed by a second
            return [200, ['job' => $mine['state'] === 'claimed' ? $this->jobOut($mine) : null]];
        }
        if (!$docker) {
            return [200, ['job' => null]];
        }
        $lease = gmdate('Y-m-d H:i:s', time() + self::LEASE_S);
        foreach ($this->rows("SELECT * FROM endpoint_agent_build_jobs WHERE state = 'queued' ORDER BY job_id LIMIT 5") as $j) {
            if ((int) $j['want_msi'] === 1 && !$msi) {
                continue;
            }
            // atomic: only one poller wins the queued row
            if ($this->run("UPDATE endpoint_agent_build_jobs SET state = 'claimed', host_id = ?, lease_expires_at = ?, updated_at = ?, note = '', log_tail = '', progress_stage = '', progress_pct = 0, progress_detail = '', progress_at = NULL WHERE job_id = ? AND state = 'queued'", [$hostId, $lease, self::now(), (int) $j['job_id']]) === 1) {
                $this->say('Agent Build Claimed', 'build host "' . $host['host_name'] . '" took the build of agent ' . $j['version'] . ' (job #' . (int) $j['job_id'] . ')');

                return [200, ['job' => $this->jobOut($this->job((int) $j['job_id']) ?? $j)]];
            }
        }

        return [200, ['job' => null]];
    }

    /** @param array<string,mixed> $j @return array<string,mixed> */
    private function jobOut(array $j): array
    {
        $exp = (int) strtotime(((string) ($j['lease_expires_at'] ?? '')) . ' UTC');

        return ['id' => (int) $j['job_id'], 'version' => (string) $j['version'], 'source_ref' => (string) $j['source_ref'], 'targets' => self::TARGETS, 'msi' => (int) $j['want_msi'] === 1,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp > 0 ? $exp : time() + self::LEASE_S)];
    }

    /**
     * The job a host call is about: it must exist, belong to THIS host (another host's job id answers exactly like a missing one) and be in one of $states.
     *
     * @param array<string,mixed> $host @param list<string> $states
     * @return array{0:?array<string,mixed>,1:?array{0:int,1:array<string,mixed>}} job, or the error answer
     */
    private function mine(array $host, mixed $jobId, array $states): array
    {
        $this->sweep();
        // the build host script sends job_id as a string of digits (JSON), the spec's reply has an integer: both are accepted, nothing else
        $id = is_int($jobId) ? $jobId : (is_string($jobId) && preg_match('/^[1-9][0-9]{0,8}\z/', $jobId) === 1 ? (int) $jobId : 0);
        $j = $id > 0 ? $this->job($id) : null;
        if ($j === null || (int) ($j['host_id'] ?? 0) !== (int) $host['host_id']) {
            return [null, [404, ['error' => 'No such job.', 'code' => 'no_job']]];
        }
        if ($j['state'] === 'cancelled') {
            return [null, [409, ['error' => 'The job was cancelled.', 'code' => 'cancelled']]];
        }
        if ($j['state'] === 'expired') {
            return [null, [410, ['error' => 'The job expired.', 'code' => 'expired']]];
        }
        if (!in_array($j['state'], $states, true)) {
            return [null, [409, ['error' => 'The job is ' . $j['state'] . '.', 'code' => 'bad_state']]];
        }

        return [$j, null];
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $in @return array{0:int,1:array<string,mixed>} */
    public function status(array $host, array $in, string $ip = ''): array
    {
        [$j, $err] = $this->mine($host, $in['job_id'] ?? null, ['claimed', 'building', 'uploading']);
        if ($j === null) {
            return $err;
        }
        $state = $in['state'] ?? null;
        if (!is_string($state) || !in_array($state, ['building', 'uploading', 'failed'], true)) {
            return [422, ['error' => 'state must be building, uploading or failed.', 'code' => 'bad_state']];
        }
        $note = self::cleanText(is_string($in['note'] ?? null) ? $in['note'] : '', 500);
        $id = (int) $j['job_id'];
        $now = self::now();
        // optional progress (build host protocol: stage, pct, detail, elapsed_s). Validated strictly when present, whatever the state; STORED only for a live state (a terminal call ignores it)
        $prog = self::parseProgress($in);
        if (is_string($prog)) {
            return [422, ['error' => $prog, 'code' => 'bad_progress']];
        }
        if ($state === 'failed') {
            $raw = is_string($in['log_tail'] ?? null) ? $in['log_tail'] : (is_string($in['log'] ?? null) ? $in['log'] : '');
            $tail = self::cleanText(mb_strcut($raw, max(0, strlen($raw) - 4096)), 4096);
            $n = $this->run("UPDATE endpoint_agent_build_jobs SET state = 'failed', note = ?, log_tail = ?, finished_at = ?, updated_at = ? WHERE job_id = ? AND state IN ('claimed','building','uploading')", [$note, $tail, $now, $now, $id]);
            $this->purge($id);
            $this->say('Agent Build Finished', 'build of agent ' . $j['version'] . " (job #$id) on \"" . $host['host_name'] . '": failed');
        } else {
            $n = $this->run("UPDATE endpoint_agent_build_jobs SET state = ?, note = ?, lease_expires_at = ?, updated_at = ? WHERE job_id = ? AND state IN ('claimed','building','uploading')", [$state, $note, gmdate('Y-m-d H:i:s', time() + self::LEASE_S), $now, $id]);
            if ($prog !== []) {
                $this->storeProgress($id, $prog, $now);
            }
        }
        $this->touch((int) $host['host_id'], $ip);
        // an UPDATE that changes nothing (the same state and note within one second) reports 0 rows: the job is re-read to tell that from a cancel or an expiry that raced this call
        $now2 = $this->job($id);
        if ($n < 0 || $now2 === null || in_array($now2['state'], ['cancelled', 'expired'], true)) {
            return [409, ['error' => 'The job changed.', 'code' => $now2['state'] ?? 'bad_state']];
        }

        return [200, ['ok' => true]];
    }

    /**
     * Validate the optional progress fields of a status call. Absent fields are fine; a present one must be exactly right (the stage on the allowlist, pct an integer 0-100,
     * detail at most 120 printable ASCII characters, elapsed_s a non-negative integer up to a week). Integers may arrive as JSON numbers or digit strings (the shell script sends strings).
     *
     * @param array<string,mixed> $in @return array<string,mixed>|string the accepted fields (stage, pct, detail), or the refusal text
     */
    public static function parseProgress(array $in): array|string
    {
        $out = [];
        $int = static function (mixed $v, int $max): ?int {
            if (is_int($v)) {
                return $v >= 0 && $v <= $max ? $v : null;
            }

            return is_string($v) && preg_match('/^(?:0|[1-9][0-9]{0,8})\z/', $v) === 1 && (int) $v <= $max ? (int) $v : null;
        };
        if (array_key_exists('stage', $in)) {
            if (!is_string($in['stage']) || !isset(self::STAGES[$in['stage']])) {
                return 'stage must be one of ' . implode(', ', array_keys(self::STAGES)) . '.';
            }
            $out['stage'] = $in['stage'];
        }
        if (array_key_exists('pct', $in)) {
            $p = $int($in['pct'], 100);
            if ($p === null) {
                return 'pct must be a whole number from 0 to 100.';
            }
            $out['pct'] = $p;
        }
        if (array_key_exists('detail', $in)) {
            if (!is_string($in['detail']) || preg_match(self::DETAIL_RE, $in['detail']) !== 1) {
                return 'detail must be at most 120 printable characters.';
            }
            $out['detail'] = self::cleanText($in['detail'], 120);
        }
        if (array_key_exists('elapsed_s', $in) && $int($in['elapsed_s'], 604800) === null) {
            return 'elapsed_s must be a whole number of seconds (0 to 604800).';
        }

        return $out;
    }

    /**
     * Store accepted progress. It never moves backwards: the percent only rises (GREATEST, in SQL, so two racing calls cannot lower it) and a stage earlier than the recorded one,
     * with its detail, is dropped.
     *
     * @param array<string,mixed> $p
     */
    private function storeProgress(int $jobId, array $p, string $now): void
    {
        $cur = $this->rows('SELECT progress_stage FROM endpoint_agent_build_jobs WHERE job_id = ?', [$jobId])[0]['progress_stage'] ?? '';
        $order = array_keys(self::STAGES);
        $curIdx = $cur === '' ? -1 : (int) array_search($cur, $order, true);
        if (isset($p['stage']) && array_search($p['stage'], $order, true) < $curIdx) {
            unset($p['stage'], $p['detail']);   // a late or replayed call about an earlier stage
        }
        $sets = ['progress_at = ?'];
        $args = [$now];
        if (isset($p['stage'])) {
            $sets[] = 'progress_stage = ?';
            $args[] = $p['stage'];
        }
        if (isset($p['pct'])) {
            $sets[] = 'progress_pct = GREATEST(progress_pct, ?)';
            $args[] = $p['pct'];
        }
        if (isset($p['detail'])) {
            $sets[] = 'progress_detail = ?';
            $args[] = $p['detail'];
        }
        $args[] = $jobId;
        $this->run('UPDATE endpoint_agent_build_jobs SET ' . implode(', ', $sets) . " WHERE job_id = ? AND state IN ('claimed','building','uploading')", $args);
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $in @return array{0:int,1:array<string,mixed>} */
    public function upload(array $host, array $in, string $ip = ''): array
    {
        [$j, $err] = $this->mine($host, $in['job_id'] ?? null, ['building', 'uploading']);
        if ($j === null) {
            return $err;
        }
        $id = (int) $j['job_id'];
        $name = $in['name'] ?? null;
        $isLinux = is_string($name) && in_array($name, self::LINUX_FILES, true);
        if (!is_string($name) || (!array_key_exists($name, self::FILES) && !$isLinux)) {
            return [422, ['error' => 'That file name is not on the list.', 'code' => 'bad_name']];
        }
        if (str_ends_with($name, '.msi') && (int) $j['want_msi'] !== 1) {
            return [422, ['error' => 'This job does not include the MSI.', 'code' => 'bad_name']];
        }
        $idx = $in['index'] ?? null;
        $tot = $in['total'] ?? null;
        $cap = $name === 'SHA256SUMS' ? self::SUMS_MAX : ($isLinux ? LinuxStore::maxBytes($name) : $this->maxBytes);
        $maxChunks = max(1, (int) ceil($cap / self::CHUNK_MAX));
        if (!is_int($idx) || !is_int($tot) || $tot < 1 || $tot > $maxChunks || $idx < 0 || $idx >= $tot) {
            return [422, ['error' => 'index/total are out of range.', 'code' => 'bad_chunk']];
        }
        $b64 = $in['data_b64'] ?? null;
        $sha = $in['sha256_chunk'] ?? null;
        if (!is_string($b64) || $b64 === '' || strlen($b64) > (int) ceil(self::CHUNK_MAX / 3) * 4 + 8 || !is_string($sha) || preg_match('/^[0-9a-fA-F]{64}\z/', $sha) !== 1) {
            return [422, ['error' => 'The chunk is missing, too large or has no checksum.', 'code' => 'bad_chunk']];
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '' || strlen($raw) > self::CHUNK_MAX) {
            return [422, ['error' => 'The chunk is not valid base64 or is larger than 1 MiB.', 'code' => 'bad_chunk']];
        }
        $sha = strtolower($sha);
        if (!hash_equals($sha, hash('sha256', $raw))) {
            return [422, ['error' => 'The chunk does not match its checksum.', 'code' => 'bad_chunk_hash']];
        }
        // the earlier chunks of this file fix "total"; a repeated chunk is a no-op when identical and a conflict when not
        foreach ($this->rows('SELECT chunk_index, chunk_total, chunk_sha256 FROM endpoint_agent_build_chunks WHERE job_id = ? AND file_name = ?', [$id, $name]) as $h) {
            if ((int) $h['chunk_total'] !== $tot) {
                return [409, ['error' => 'total differs from the earlier chunks of this file.', 'code' => 'bad_chunk']];
            }
            if ((int) $h['chunk_index'] === $idx) {
                return hash_equals((string) $h['chunk_sha256'], $sha) ? [200, ['ok' => true, 'duplicate' => true]] : [409, ['error' => 'A different chunk with this index was already received.', 'code' => 'conflict']];
            }
        }
        $fileBytes = (int) ($this->rows('SELECT COALESCE(SUM(size_bytes), 0) AS s FROM endpoint_agent_build_chunks WHERE job_id = ? AND file_name = ?', [$id, $name])[0]['s'] ?? 0);
        $jobBytes = (int) ($this->rows('SELECT COALESCE(SUM(size_bytes), 0) AS s FROM endpoint_agent_build_chunks WHERE job_id = ?', [$id])[0]['s'] ?? 0);
        $len = strlen($raw);
        // the job cap allows for the two Windows exe + the MSI (JOB_CAP_FACTOR) and, additively, the optional Linux files: two binaries, two tarballs (each up to
        // the same per-file cap as a Windows binary) and the small install-linux.sh script.
        $jobCap = self::JOB_CAP_FACTOR * $this->maxBytes + self::SUMS_MAX + 4 * $this->maxBytes + LinuxStore::SCRIPT_MAX;
        if ($fileBytes + $len > $cap || $jobBytes + $len > $jobCap) {
            return [413, ['error' => 'The file or the job is larger than allowed.', 'code' => 'too_large']];
        }
        $dir = $this->jobDir($id, true);
        if ($dir === null) {
            return [503, ['error' => 'The build folder is not writable.', 'code' => 'storage']];
        }
        $store = bin2hex(random_bytes(16));
        // the row first: its primary key makes two parallel copies of the same chunk race to one winner
        if ($this->run('INSERT INTO endpoint_agent_build_chunks (job_id, file_name, chunk_index, chunk_total, chunk_sha256, size_bytes, storage_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$id, $name, $idx, $tot, $sha, $len, $store, self::now()]) !== 1) {
            return [409, ['error' => 'That chunk was already received.', 'code' => 'conflict']];
        }
        if (@file_put_contents("$dir/$store", $raw, LOCK_EX) !== $len) {
            @unlink("$dir/$store");
            $this->run('DELETE FROM endpoint_agent_build_chunks WHERE job_id = ? AND file_name = ? AND chunk_index = ?', [$id, $name, $idx]);

            return [503, ['error' => 'The chunk could not be stored.', 'code' => 'storage']];
        }
        @chmod("$dir/$store", 0600);
        $this->run("UPDATE endpoint_agent_build_jobs SET state = 'uploading', lease_expires_at = ?, updated_at = ? WHERE job_id = ? AND state IN ('building','uploading')", [gmdate('Y-m-d H:i:s', time() + self::LEASE_S), self::now(), $id]);
        $this->touch((int) $host['host_id'], $ip);

        return [200, ['ok' => true, 'duplicate' => false]];
    }

    /**
     * @param array<string,mixed> $host @param array<string,mixed> $in
     * @param (callable(int,string):callable(string,string,string,array<string,mixed>):array{ok:bool,message:string})|null $publisherFor (requesting user id, name) => publisher
     * @return array{0:int,1:array<string,mixed>}
     */
    public function complete(array $host, array $in, ?callable $publisherFor, string $ip = ''): array
    {
        [$j, $err] = $this->mine($host, $in['job_id'] ?? null, ['building', 'uploading']);
        if ($j === null) {
            return $err;
        }
        $id = (int) $j['job_id'];
        $man = $in['manifest'] ?? null;
        if (!is_array($man) || !is_array($man['files'] ?? null)) {
            return [422, ['error' => 'The manifest is missing.', 'code' => 'bad_manifest']];
        }
        $files = [];
        foreach ($man['files'] as $n => $h) {
            if (!is_string($n) || (!array_key_exists($n, self::FILES) && !in_array($n, self::LINUX_FILES, true)) || !is_string($h) || preg_match('/^[0-9a-fA-F]{64}\z/', $h) !== 1) {
                return [422, ['error' => 'The manifest lists a file or a checksum that is not allowed.', 'code' => 'bad_manifest']];
            }
            $files[$n] = strtolower($h);
        }
        if (!is_string($man['commit'] ?? null) || preg_match('/^[0-9a-fA-F]{7,64}\z/', $man['commit']) !== 1) {
            return [422, ['error' => 'The manifest needs the commit.', 'code' => 'bad_manifest']];
        }
        $commit = strtolower($man['commit']);
        $go = self::cleanText(is_string($man['go_version'] ?? null) ? $man['go_version'] : '', 40);
        $built = self::cleanText(is_string($man['built_at'] ?? null) ? $man['built_at'] : '', 40);
        // one winner: whoever moves the job to "verifying" first verifies it
        if ($this->run("UPDATE endpoint_agent_build_jobs SET state = 'verifying', updated_at = ?, note = '' WHERE job_id = ? AND state IN ('building','uploading')", [self::now(), $id]) !== 1) {
            return [409, ['error' => 'The job is already being completed.', 'code' => 'bad_state']];
        }
        $this->touch((int) $host['host_id'], $ip);
        $res = $this->verify($j, $files);
        if (!$res['ok']) {
            $this->finish($id, 'failed', 'Nothing was stored: ' . $res['message']);
            $this->say('Agent Build Finished', 'build of agent ' . $j['version'] . " (job #$id): rejected at verification - " . $res['message']);

            return [422, ['error' => self::cleanText($res['message'], 300), 'code' => 'verify_failed']];
        }
        $this->run("UPDATE endpoint_agent_build_jobs SET state = 'built', manifest = ?, note = ?, updated_at = ? WHERE job_id = ?",
            [json_encode(['files' => $files, 'commit' => $commit, 'go_version' => $go, 'built_at' => $built]), 'Built from commit ' . $commit . ' and verified.', self::now(), $id]);
        $this->say('Agent Build Finished', 'build of agent ' . $j['version'] . " (job #$id) on \"" . $host['host_name'] . "\": verified (commit $commit)");
        if ((int) $j['auto_publish'] === 1 && $publisherFor !== null) {
            $p = $this->publish($id, $publisherFor((int) $j['requested_by'], (string) $j['requested_by_name']));

            return [200, ['ok' => true, 'state' => $p['ok'] ? 'published' : 'failed', 'message' => self::cleanText($p['message'], 300)]];
        }

        return [200, ['ok' => true, 'state' => 'built', 'message' => 'Verified. An administrator publishes it.']];
    }

    /**
     * Reassemble every uploaded file, recompute SHA-256 and compare with the manifest and SHA256SUMS, run the existing validators. Stores nothing in the agent tables.
     *
     * @param array<string,mixed> $j @param array<string,string> $manifest name => sha256
     * @return array{ok:bool,message:string}
     */
    private function verify(array $j, array $manifest): array
    {
        $id = (int) $j['job_id'];
        $dir = $this->jobDir($id, false);
        $need = ['rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'SHA256SUMS'];
        if ((int) $j['want_msi'] === 1) {
            $need[] = 'rivetit-agent-windows-amd64.msi';
        }
        foreach ($need as $n) {
            if ($n !== 'SHA256SUMS' && !isset($manifest[$n])) {   // the script lists the built files; SHA256SUMS describes them and is checked against them below
                return ['ok' => false, 'message' => "$n is missing from the manifest."];
            }
        }
        // the optional Linux files (self::LINUX_FILES) may also be in the manifest: not required, not reassembled or validated here (LinuxStore does that, at publish
        // time, since a Linux-only problem must never fail the Windows build) — but if SHA256SUMS already names one, it must agree with the manifest right away.
        if (array_diff(array_keys($manifest), [...$need, ...self::LINUX_FILES]) !== []) {
            return ['ok' => false, 'message' => 'The manifest lists a file this job did not ask for.'];
        }
        if ($dir === null || !is_dir($dir)) {
            return ['ok' => false, 'message' => 'No upload was received.'];
        }
        $sums = [];
        $paths = [];
        $jobBytes = 0;
        foreach ($need as $n) {
            $r = $this->assemble($id, $n, $dir);
            if (is_string($r)) {
                return ['ok' => false, 'message' => "$n $r"];
            }
            [$path, $size, $hash] = $r;
            $jobBytes += $size;
            if (isset($manifest[$n]) && !hash_equals($manifest[$n], $hash)) {
                return ['ok' => false, 'message' => "The SHA-256 of $n does not match the manifest."];
            }
            $manifest[$n] ??= $hash;
            $paths[$n] = $path;
            if ($n === 'SHA256SUMS') {
                $sums = Feed::parseSums((string) file_get_contents($path));
            }
        }
        if ($jobBytes > self::JOB_CAP_FACTOR * $this->maxBytes + self::SUMS_MAX) {
            return ['ok' => false, 'message' => 'The job is larger than allowed.'];
        }
        foreach ($need as $n) {
            if ($n !== 'SHA256SUMS' && (!isset($sums[$n]) || !hash_equals($sums[$n], $manifest[$n]))) {
                return ['ok' => false, 'message' => "SHA256SUMS does not list $n with the same checksum."];
            }
        }
        // a Linux file in the manifest is not reassembled here (LinuxStore validates and stores it at publish time, so a Linux-only problem never fails the
        // Windows build), but if SHA256SUMS already names it, the two must agree now rather than silently diverge until publish.
        foreach (self::LINUX_FILES as $n) {
            if (isset($manifest[$n], $sums[$n]) && !hash_equals($sums[$n], $manifest[$n])) {
                return ['ok' => false, 'message' => "SHA256SUMS does not list $n with the same checksum."];
            }
        }
        foreach ($need as $n) {
            if ($n === 'SHA256SUMS') {
                continue;
            }
            $bad = str_ends_with($n, '.msi')
                ? \RivetCore\Rmm\Binaries\MsiInspector::inspect($paths[$n], self::FILES[$n], $this->maxBytes)
                : \RivetCore\Rmm\Binaries\BinaryInspector::inspect($paths[$n], self::FILES[$n], $this->maxBytes);
            if (is_string($bad)) {
                return ['ok' => false, 'message' => "$n: $bad"];
            }
            if (str_ends_with($n, '.exe') && !self::versionMatches($paths[$n], (string) $j['version'])) {
                return ['ok' => false, 'message' => "$n carries a different version than the requested " . $j['version'] . '.'];
            }
        }
        foreach ($paths as $p) {
            @unlink($p);   // the chunks stay (publish reassembles and re-hashes); only the copies go
        }

        return ['ok' => true, 'message' => 'ok'];
    }

    /**
     * The validators do not read a version. A Go build made by the build host script embeds "RIVETIT_BUILD_VERSION=<version>" when it can; only a CLEAR contradiction (a marker with another
     * version) fails here. A binary without the marker passes (the version the administrator asked for is what the server records).
     */
    private static function versionMatches(string $path, string $version): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $data = (string) stream_get_contents($fh, 67108864);
        fclose($fh);
        if (preg_match_all('/RIVETIT_BUILD_VERSION=([0-9A-Za-z.\-]{1,40})/', $data, $m) > 0) {
            foreach ($m[1] as $v) {
                if ($v !== $version) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{0:string,1:int,2:string}|string path, size, sha256 of the reassembled file, or the reason (text starting with a verb) it cannot be */
    private function assemble(int $jobId, string $name, string $dir): array|string
    {
        $rows = $this->rows('SELECT chunk_index, chunk_total, chunk_sha256, size_bytes, storage_name FROM endpoint_agent_build_chunks WHERE job_id = ? AND file_name = ? ORDER BY chunk_index', [$jobId, $name]);
        if ($rows === []) {
            return 'was not uploaded.';
        }
        $total = (int) $rows[0]['chunk_total'];
        if (count($rows) !== $total) {
            return 'is incomplete (' . count($rows) . " of $total chunks).";
        }
        $out = $dir . '/assembled-' . md5($name . $jobId);
        $fh = fopen($out, 'wb');
        if ($fh === false) {
            return 'could not be assembled.';
        }
        @chmod($out, 0600);
        $ctx = hash_init('sha256');
        $size = 0;
        $cap = $name === 'SHA256SUMS' ? self::SUMS_MAX : $this->maxBytes;
        foreach ($rows as $i => $r) {
            $sn = (string) $r['storage_name'];
            $b = ((int) $r['chunk_index'] === $i && preg_match('/^[0-9a-f]{32}\z/', $sn) === 1) ? @file_get_contents("$dir/$sn") : false;
            if ($b === false || !hash_equals((string) $r['chunk_sha256'], hash('sha256', $b))) {
                fclose($fh);
                @unlink($out);

                return 'lost or changed a chunk in quarantine.';
            }
            $size += strlen($b);
            if ($size > $cap) {
                fclose($fh);
                @unlink($out);

                return 'is larger than allowed.';
            }
            hash_update($ctx, $b);
            fwrite($fh, $b);
        }
        fclose($fh);

        return [$out, $size, hash_final($ctx)];
    }

    // ------------------------------------------------------------------ publish

    /**
     * Publish a verified (state built) job through the existing code. The files are re-hashed against the stored manifest first (they sat in quarantine). One winner.
     *
     * @param callable(string,string,string,array<string,mixed>):array{ok:bool,message:string} $publisher (kind exe|msi|linux, path, arch, job)
     * @return array{ok:bool,message:string}
     */
    public function publish(int $jobId, callable $publisher): array
    {
        $j = $this->job($jobId);
        if ($j === null || $j['state'] !== 'built') {
            return ['ok' => false, 'message' => 'That build is not ready to publish.'];
        }
        if ($this->run("UPDATE endpoint_agent_build_jobs SET state = 'verifying', updated_at = ? WHERE job_id = ? AND state = 'built'", [self::now(), $jobId]) !== 1) {
            return ['ok' => false, 'message' => 'That build is being published already.'];
        }
        $man = json_decode((string) $j['manifest'], true);
        $dir = $this->jobDir($jobId, false);
        $paths = [];
        $linuxPaths = [];
        foreach (is_array($man) ? (array) ($man['files'] ?? []) : [] as $n => $h) {
            if (!is_string($n) || $n === 'SHA256SUMS') {
                continue;
            }
            $isLinux = in_array($n, self::LINUX_FILES, true);
            if (!array_key_exists($n, self::FILES) && !$isLinux) {
                continue;
            }
            $r = $dir !== null ? $this->assemble($jobId, $n, $dir) : 'is gone.';
            if (is_string($r) || !hash_equals((string) $h, $r[2])) {
                $this->finish($jobId, 'failed', "Nothing was stored: $n changed in quarantine.");

                return ['ok' => false, 'message' => "Nothing was stored: $n changed in quarantine."];
            }
            if ($isLinux) {
                $linuxPaths[$n] = $r[0];
            } else {
                $paths[$n] = $r[0];
            }
        }
        // Linux files first (RivetIT's own build-host pattern): storing them again is harmless (identical bytes replay), so a retry of Publish still works, and
        // a Linux-only problem is surfaced before anything Windows-side is touched. They stay NOT current (activate false) until the whole publish succeeds below,
        // mirroring the Windows "make current" choice (job.activate) rather than going live on their own.
        $lxIds = [];
        foreach ($linuxPaths as $n => $path) {
            $lr = LinuxStore::store($this->db, $path, $n, (string) $j['version'], (int) $j['requested_by'], [
                'activate' => false, 'ring' => in_array($j['ring'] ?? null, ['pilot', 'stable'], true) ? $j['ring'] : null, 'pct' => (int) ($j['rollout_pct'] ?? 100),
                'source' => 'build_host', 'notes' => 'Built on build host (job #' . $jobId . ')',
            ]);
            if (!$lr['ok']) {
                $msg = (string) ($lr['error'] ?? "$n could not be stored.");
                $this->finish($jobId, 'failed', "Nothing was stored: $msg");
                $this->say('Agent Build Finished', 'build of agent ' . $j['version'] . " (job #$jobId): Linux file $n rejected at publish - $msg Nothing was stored.");

                return ['ok' => false, 'message' => "Nothing was stored: $msg"];
            }
            $lxIds[] = (int) $lr['file_id'];
        }
        $problems = 0;
        $stored = 0;
        $msgs = [];
        foreach ($paths as $n => $path) {
            $res = $publisher(str_ends_with($n, '.msi') ? 'msi' : 'exe', $path, self::FILES[$n], $j);
            $msgs[] = $res['message'];
            $res['ok'] ? $stored++ : $problems++;
        }
        // RivetCore rc.30: the Linux agent PROGRAMS also go into Core's own binaries with os=linux (publisher kind 'linux'), so enrolled Linux devices are offered
        // them as their self-update through agent_update, with the ring and rollout chosen for this build. Only once every Windows file went through (a half
        // published build offers nothing new to Linux either); a failure here is a problem of this publish like a Windows one. install-linux.sh and the tarballs
        // are install-time files only and stay in the hosted-files table.
        if ($problems === 0) {
            foreach ($linuxPaths as $n => $path) {
                $lp = LinuxStore::parseName($n);
                if ($lp === null || $lp['kind'] !== 'binary') {
                    continue;
                }
                $res = $publisher('linux', $path, $lp['arch'], $j);
                if (!$res['ok']) {
                    array_unshift($msgs, $n . ' was not published as a Linux self-update: ' . $res['message']);   // first, so the capped job result still shows why
                    $problems++;
                }
            }
        }
        if ($problems === 0 && $lxIds !== [] && (int) ($j['activate'] ?? 0) === 1) {
            foreach ($lxIds as $lid) {
                LinuxStore::setCurrent($this->db, $lid);
            }
        }
        $lxNote = $lxIds !== [] ? ' (' . count($lxIds) . ' Linux file' . (count($lxIds) === 1 ? '' : 's') . ')' : '';
        $msg = $stored === 0 ? 'Nothing was stored. ' . implode(' ', $msgs) : ($problems === 0 ? 'Agent ' . $j['version'] . " published ($stored files)$lxNote." : 'Agent ' . $j['version'] . " published with $problems problem(s): " . implode(' ', $msgs));
        $state = $stored > 0 && $problems === 0 ? 'published' : 'failed';
        $now = self::now();
        $this->run('UPDATE endpoint_agent_build_jobs SET state = ?, result = ?, finished_at = ?, updated_at = ? WHERE job_id = ?', [$state, self::cleanText($msg, 500), $now, $now, $jobId]);
        $this->purge($jobId);
        $this->say('Agent Build Published', 'build of agent ' . $j['version'] . " (job #$jobId): $state - " . self::cleanText($msg, 300));

        return ['ok' => $state === 'published', 'message' => $msg];
    }
}

/**
 * The publisher for a local build: the existing ability check, then RmmAdmin::uploadBinary() / uploadMsiTemplate() (which validate again and audit) as the administrator who
 * asked for the build. Ring, rollout and "make current" are the values chosen at request time. Kind 'linux' (RivetCore rc.30) publishes a Linux agent program with
 * os=linux for self-update; ring and rollout apply, "make current" does not.
 *
 * @return callable(string,string,string,array<string,mixed>):array{ok:bool,message:string}
 */
function rivetLocalBuildPublisher(object $rmm, object $who, int $userId): callable
{
    return static function (string $kind, string $path, string $arch, array $job) use ($rmm, $who, $userId): array {
        $denied = $rmm->authorizer()->check($userId, \RivetCore\Rmm\Authz\RmmAbility::BINARY_PUBLISH, 0, false);
        if ($denied !== null) {
            return ['ok' => false, 'message' => 'Not published: ' . $denied];
        }
        $ring = in_array($job['ring'] ?? '', ['pilot', 'stable'], true) ? $job['ring'] : '';
        if ($kind === 'linux') {
            // a Linux agent program for self-update (os=linux in Core's tables); never "current" (that flag only picks the Windows binary installers are stamped from)
            $res = $rmm->admin()->uploadBinary($who, $path, (string) $job['version'], $arch, ['os' => \RivetCore\Rmm\Support\AgentOs::LINUX, 'activate' => false,
                'release_ring' => $ring === '' ? null : $ring, 'rollout_pct' => max(0, min(100, (int) $job['rollout_pct']))]);

            return ['ok' => $res->ok, 'message' => (string) $res->message];
        }
        $o = ['activate' => (int) ($job['activate'] ?? 0) === 1];
        $res = $kind === 'msi'
            ? $rmm->admin()->uploadMsiTemplate($who, $path, (string) $job['version'], $arch, $o)
            : $rmm->admin()->uploadBinary($who, $path, (string) $job['version'], $arch, $o + ['release_ring' => $ring === '' ? null : $ring, 'rollout_pct' => max(0, min(100, (int) $job['rollout_pct']))]);

        return ['ok' => $res->ok, 'message' => (string) $res->message];
    };
}

/** The LocalBuild object for the admin page and the post handlers (audit rows go through logAction()). */
function rivetLocalBuild(\mysqli $db, object $rmm): LocalBuild
{
    return new LocalBuild($db, defined('EA_BUILD_DIR') ? (string) EA_BUILD_DIR : dirname(__DIR__) . '/backups/endpoint-build', $rmm->binaryStore()->maxBytes(), static function (string $a, string $t): void {
        logAction('Endpoint Agent', $a, $t);
    });
}

/**
 * What the Local build section shows. The new-token flash is consumed here (printed once).
 *
 * @param array<string,mixed> $buildView Build::view() (for the suggested version and the default provider)
 * @return array<string,mixed>
 */
function rivetLocalBuildView(LocalBuild $lb, Feed $feed, string $serverUrl, array $buildView, ?string $highestHosted): array
{
    $ready = $lb->ready();
    $token = null;
    if (isset($_SESSION['rmm_bh_token']) && is_array($_SESSION['rmm_bh_token'])) {
        $t = $_SESSION['rmm_bh_token'];
        unset($_SESSION['rmm_bh_token']);
        if (is_string($t['name'] ?? null) && is_string($t['token'] ?? null) && time() - (int) ($t['at'] ?? 0) < 600) {
            $token = ['name' => $t['name'], 'token' => $t['token'], 'rotated' => !empty($t['rotated'])];
        }
    }
    $hosts = $ready ? $lb->hosts() : [];
    $latest = $buildView['latest'] ?? null;
    if ($highestHosted !== null && ($latest === null || version_compare($highestHosted, (string) $latest) > 0)) {
        $latest = $highestHosted;
    }

    return ['ready' => $ready, 'hosts' => $hosts, 'jobs' => $ready ? $lb->jobs() : [], 'suggest' => Build::suggest($latest), 'server_url' => $serverUrl, 'active' => $ready && $lb->hasActive(), 'token' => $token,
        'default' => !empty($buildView['has_token']) || $hosts === [] ? 'github' : 'local'];
}
