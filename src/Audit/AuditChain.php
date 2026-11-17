<?php

declare(strict_types=1);

namespace RivetMSP\Audit;

use RivetMSP\Platform\PlatformSettings;

/**
 * A hash chain over audit_events, kept in this edition (RivetCore's AuditService only inserts rows): every row carries prev_hash (the
 * previous row's row_hash) and row_hash (over its own fields and prev_hash), so an edit, a deleted row or a reordering shows up as a
 * break when the chain is verified. When the install has a settings key the hash is an HMAC keyed from it, so someone who can write the
 * database but does not hold the key cannot recompute the chain.
 *
 * Sealing: rows are sealed in audit_id order, under a named lock, by AuditService (right after each event) and by the cron. A row that
 * another path inserted is sealed the next time. Retention (Core's RetentionService) may delete the OLDEST rows: that is a legitimate
 * way for the chain to lose its head and is accepted; anything else missing is a break.
 *
 * Anchors (MSP-DS-173): the last verified head is kept twice, in the database (with a keyed mac) and in config/audit_chain_head.json (0600, same
 * mac), so someone who can only write the database cannot cut the newest rows and move the head; a chain that says "plain" on an install with a
 * key is reported as a downgrade. With a key the anchors fail closed: a missing, unreadable or unsigned anchor file is a failure whatever the
 * database says (only an install with no sealed rows is exempt), a cut is accepted only when the retention window explains it, and the layout
 * (mode and the start of the keyed segment, hmac_from) comes from the SIGNED anchor, never from the database alone. After restoring a database
 * backup, to move a plain chain onto the key, or once after upgrading from 26.10.12, use reanchor() (Administration > Audit trail).
 * The whole state machine is written down in docs/AUDIT_CHAIN.md.
 *
 * Limits (honest ones): an administrator with database AND key access can rebuild the chain; the newest rows are covered only from the
 * moment they are sealed; the verifier needs the key that sealed the chain (a changed key is reported as such, not as tampering).
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const LOCK = 'rivetmsp_audit_chain';
    private const FIELDS = ['audit_id', 'event_type', 'actor_user_id', 'entity_type', 'entity_id', 'action', 'summary', 'metadata_json', 'ip_address', 'user_agent', 'request_id', 'created_at'];

    /** MSP-DS-173: the head anchor lives in a FILE (not the database) so someone who can only write the database cannot move it. */
    private string $anchorFile;
    private ?int $retentionDays;
    /** @var 'ok'|'absent'|'unreadable'|'invalid' state of the anchor file at the last readAnchor() */
    private string $anchorState = 'absent';

    public function __construct(private \mysqli $db, private ?string $key = null, ?string $anchorFile = null, ?int $retentionDays = null)
    {
        $this->key ??= self::deriveKey();
        $this->anchorFile = $anchorFile ?? (string) ($GLOBALS['config_audit_anchor_file'] ?? dirname(__DIR__, 2) . '/config/audit_chain_head.json');
        $this->retentionDays = $retentionDays;   // null: effectiveRetentionDays() when needed (explicit values are for tests)
    }

    /**
     * M3-17: the audit retention window in days, ONE function for the cron job that deletes and the verifier that explains the gap: the compliance
     * profile floor, then the SecurityBaseline floor for any value of 1 or more; 0 keeps forever (and then nothing explains a gap).
     */
    public static function retentionFromSettings(int $stored, string $profile): int
    {
        $days = class_exists(\RivetCore\Compliance\RetentionPolicy::class) ? \RivetCore\Compliance\RetentionPolicy::effectiveDays($profile, $stored) : max(0, $stored);
        if ($days >= 1 && class_exists(\RivetCore\Security\SecurityBaseline::class)) {
            $days = \RivetCore\Security\SecurityBaseline::auditRetentionDays($days);
        }

        return max(0, $days);
    }

    /** M3-17: {@see retentionFromSettings()} on what the settings row says now (read here, so web requests and cron cannot disagree). */
    public static function effectiveRetentionDays(\mysqli $db): int
    {
        $stored = 365;
        $profile = 'none';
        try {
            $r = $db->query('SELECT config_compliance_profile, config_audit_retention_days FROM settings WHERE company_id = 1');
            $row = $r ? $r->fetch_assoc() : null;
            if ($row) {
                $stored = (int) ($row['config_audit_retention_days'] ?? 365);
                $profile = (string) ($row['config_compliance_profile'] ?? 'none');
            }
        } catch (\Throwable $e) {
            // the column is not there yet (code updated, database not): the default
        }

        return self::retentionFromSettings($stored, $profile);
    }

    private function retentionDays(): int
    {
        return $this->retentionDays ??= self::effectiveRetentionDays($this->db);
    }

    /** Short fingerprint of the current key (what audit_chain_key_id holds); '' without a key. */
    private function keyId(): string
    {
        return $this->key !== null ? substr(hash('sha256', $this->key), 0, 12) : '';
    }

    /**
     * MSP-DS-173 / MRR-18: keyed signature of a head. It covers the key id (so the key id cannot be swapped in the database without the
     * signature failing), the time it was recorded (the retention test uses it), the start of the HMAC segment (re-anchored plain chains) and the
     * mode, so none of them can be changed in the database alone (M3-13).
     */
    private function headMac(int $id, string $hash, string $at, string $kid, int $from, string $mode): string
    {
        $data = "audit-chain-head|$id|$hash|$at|$kid|$from|$mode";

        return $this->key !== null ? hash_hmac('sha256', $data, $this->key) : hash('sha256', $data);
    }

    /** @return array{id:int,hash:string,at:string,kid:string,from:int,mode:string,mac:string} */
    private function signHead(int $id, string $hash, int $from): array
    {
        $at = gmdate('c');
        $kid = $this->keyId();
        $mode = $this->key !== null ? 'hmac' : 'plain';

        return ['id' => $id, 'hash' => $hash, 'at' => $at, 'kid' => $kid, 'from' => $from, 'mode' => $mode, 'mac' => $this->headMac($id, $hash, $at, $kid, $from, $mode)];
    }

    /** @param array<string,mixed> $h */
    private function headSigned(array $h): bool
    {
        if (!isset($h['id'], $h['hash'], $h['mac'], $h['at'], $h['kid'], $h['from'], $h['mode'])) {
            return false;
        }

        return hash_equals($this->headMac((int) $h['id'], (string) $h['hash'], (string) $h['at'], (string) $h['kid'], (int) $h['from'], (string) $h['mode']), (string) $h['mac'])
            && (string) $h['kid'] === $this->keyId();
    }

    /** @return array{id:int,hash:string,at:string,kid:string,from:int,mode:string,mac:string}|null  (sets $this->anchorState) */
    private function readAnchor(): ?array
    {
        $this->anchorState = 'absent';
        if (!file_exists($this->anchorFile)) {
            return null;
        }
        $raw = is_file($this->anchorFile) ? @file_get_contents($this->anchorFile) : false;
        if ($raw === false) {
            $this->anchorState = 'unreadable';

            return null;
        }
        $a = json_decode($raw, true);
        if (!is_array($a) || !isset($a['id'], $a['hash'])) {
            $this->anchorState = 'invalid';

            return null;
        }
        $this->anchorState = 'ok';

        return ['id' => (int) $a['id'], 'hash' => (string) $a['hash'], 'at' => (string) ($a['at'] ?? ''), 'kid' => (string) ($a['kid'] ?? ''), 'from' => (int) ($a['from'] ?? 0), 'mode' => (string) ($a['mode'] ?? ''), 'mac' => (string) ($a['mac'] ?? '')];
    }

    /** @param array{id:int,hash:string,at:string,kid:string,from:int,mode:string,mac:string} $head */
    private function writeAnchor(array $head): bool
    {
        $dir = dirname($this->anchorFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = @tempnam($dir, '.anchor.');
        if ($tmp === false) {
            return false;   // never break the audited action or the verify run; the next verify reports the missing anchor (fail closed)
        }
        $ok = @file_put_contents($tmp, json_encode($head)) !== false && @chmod($tmp, 0600) && @rename($tmp, $this->anchorFile);
        if (!$ok) {
            @unlink($tmp);
        }

        return $ok;
    }

    /** HMAC key derived from the settings key (null when the install has none: plain SHA-256 then). */
    public static function deriveKey(): ?string
    {
        $k = (string) ($GLOBALS['config_settings_enc_key'] ?? '');

        return $k === '' ? null : hash_hmac('sha256', 'rivetmsp-audit-chain-v1', $k);
    }

    /** Pure. @param array<string,mixed> $row */
    public static function rowHash(array $row, string $prevHash, ?string $key): string
    {
        $vals = [];
        foreach (self::FIELDS as $f) {
            $vals[] = $row[$f] === null ? null : (string) $row[$f];
        }
        $data = $prevHash . "\n" . json_encode($vals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $key !== null ? hash_hmac('sha256', $data, $key) : hash('sha256', $data);
    }

    /** Hold the chain lock around a block (insert + seal). Returns false when the lock could not be had (the caller proceeds unlocked). */
    public function lock(int $seconds = 3): bool
    {
        $r = $this->db->query("SELECT GET_LOCK('" . self::LOCK . "', $seconds)");
        $x = $r ? $r->fetch_row() : null;

        return $x !== null && (int) $x[0] === 1;
    }

    public function unlock(): void
    {
        $this->db->query("DO RELEASE_LOCK('" . self::LOCK . "')");
    }

    /** The key rows are sealed with now: none while the chain is still recorded as plain (it stays consistent until it is re-anchored). */
    private function sealKey(): ?string
    {
        return PlatformSettings::get($this->db, 'audit_chain_mode', '') === 'plain' ? null : $this->key;
    }

    /**
     * Seal every unsealed row newer than the newest sealed one, oldest first.
     *
     * @return list<array<string,mixed>> the rows that were sealed (with prev_hash and row_hash), for the sink
     */
    public function seal(int $max = 2000): array
    {
        $this->ensureMode();
        $sealKey = $this->sealKey();
        $res = $this->db->query('SELECT audit_id, row_hash FROM audit_events WHERE row_hash IS NOT NULL ORDER BY audit_id DESC LIMIT 1');
        $last = $res ? $res->fetch_assoc() : null;
        $prev = $last ? (string) $last['row_hash'] : self::GENESIS;
        $after = $last ? (int) $last['audit_id'] : 0;
        $res = $this->db->query("SELECT * FROM audit_events WHERE row_hash IS NULL AND audit_id > $after ORDER BY audit_id ASC LIMIT " . max(1, $max));
        $sealed = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $hash = self::rowHash($row, $prev, $sealKey);
            $this->db->query("UPDATE audit_events SET prev_hash = '$prev', row_hash = '$hash' WHERE audit_id = " . (int) $row['audit_id'] . ' AND row_hash IS NULL');
            $row['prev_hash'] = $prev;
            $row['row_hash'] = $hash;
            $sealed[] = $row;
            $prev = $hash;
        }

        return $sealed;
    }

    /**
     * Record how the chain is keyed the first time it is used, so the verifier can tell "key changed" from "tampered". On an install with a key
     * the first use also writes the GENESIS anchor (id 0), so a fresh install has a signed anchor before its first event is verified (M3-11:
     * "a keyed install with no anchor file is broken" must not hit a brand-new install). Never on an install that already has sealed rows.
     */
    private function ensureMode(): void
    {
        if (PlatformSettings::get($this->db, 'audit_chain_mode', '') === '') {
            PlatformSettings::set($this->db, 'audit_chain_mode', $this->key !== null ? 'hmac' : 'plain');
            PlatformSettings::set($this->db, 'audit_chain_key_id', $this->keyId());
            if ($this->key !== null && !$this->hasSealedRows()) {
                $this->readAnchor();
                if ($this->anchorState === 'absent') {
                    $this->writeAnchor($this->signHead(0, self::GENESIS, 0));
                }
            }
        }
    }

    private function hasSealedRows(): bool
    {
        $r = $this->db->query('SELECT 1 FROM audit_events WHERE row_hash IS NOT NULL LIMIT 1');

        return $r && $r->fetch_row() !== null;
    }

    /**
     * Walk the sealed rows in order and check every link and every hash (no anchors).
     *
     * $mode 'plain': every row plain. 'hmac': rows with audit_id below $from plain, the others keyed. 'detect' (re-anchor without a signed
     * anchor): plain rows first, keyed rows after, the boundary is reported in plain_seen / boundary (M3-12); a plain row after a keyed one is refused.
     *
     * @param array<string,mixed> $out
     * @return array<string,mixed> $out, with status 'broken' on the first bad row
     */
    private function walk(array $out, string $mode, int $from): array
    {
        $prev = null;
        $afterId = 0;
        $first = true;
        $seenKeyed = false;
        while (true) {
            $res = $this->db->query("SELECT * FROM audit_events WHERE row_hash IS NOT NULL AND audit_id > $afterId ORDER BY audit_id ASC LIMIT 2000");
            $n = 0;
            while ($res && ($row = $res->fetch_assoc())) {
                $n++;
                $id = (int) $row['audit_id'];
                $afterId = $id;
                if ($first) {
                    $out['first_id'] = $id;
                    $out['first_prev'] = (string) $row['prev_hash'];
                    $first = false;       // the oldest surviving row is the trust start: retention may have removed what came before it
                } elseif ($row['prev_hash'] !== $prev) {
                    return $this->broken($out, $id, 'its prev_hash does not match the row before it (a row was removed, inserted or reordered)');
                }
                if ($mode === 'detect') {
                    $plainOk = self::rowHash($row, (string) $row['prev_hash'], null) === $row['row_hash'];
                    $keyedOk = !$plainOk && $this->key !== null && self::rowHash($row, (string) $row['prev_hash'], $this->key) === $row['row_hash'];
                    if ($plainOk && $seenKeyed) {
                        return $this->broken($out, $id, 'it is hashed without the key although rows before it are keyed (the chain was rehashed)');
                    }
                    if (!$plainOk && !$keyedOk) {
                        return $this->broken($out, $id, 'its contents no longer match its hash (the row was edited)');
                    }
                    if ($plainOk) {
                        $out['plain_seen'] = true;
                    } elseif (!$seenKeyed) {
                        $seenKeyed = true;
                        $out['boundary'] = $id;
                    }
                } else {
                    // rows before a re-anchored plain chain's keyed segment were sealed unkeyed; the first keyed row's prev_hash binds them
                    $rowKey = ($mode === 'plain' || $id < $from) ? null : $this->key;
                    if (self::rowHash($row, (string) $row['prev_hash'], $rowKey) !== $row['row_hash']) {
                        return $this->broken($out, $id, 'its contents no longer match its hash (the row was edited)');
                    }
                }
                $prev = (string) $row['row_hash'];
                $out['checked']++;
                $out['head_id'] = $id;
                $out['head_hash'] = $prev;
            }
            if ($n === 0) {
                break;
            }
        }

        return $out;
    }

    /**
     * True when rows up to a head recorded at $at may legitimately be gone: retention is on and the head is older than its window (M3-10: this is
     * the ONLY thing that explains a cut; "the surviving row follows the head's hash" is true of any DELETE audit_id <= N).
     */
    private function retentionExplains(string $at): bool
    {
        $t = strtotime($at);
        $days = $this->retentionDays();

        return $days > 0 && $t !== false && $t < time() - $days * 86400;
    }

    /** @param array<string,mixed> $out @return array<string,mixed> */
    private function failClosed(array $out, string $why, string $cause = ''): array
    {
        $out['status'] = 'broken';
        $out['reason'] = $why;
        $out['cause'] = $cause;

        return $out;
    }

    /**
     * Does one anchor agree with the rows that survive?
     *
     * @param array<string,mixed> $out
     * @param array{id:int,hash:string,at:string,where:string} $an
     * @return array<string,mixed>|null a broken result, or null when the anchor is consistent
     */
    private function checkAnchor(array $out, array $an): ?array
    {
        $sid = $an['id'];
        if ($out['checked'] === 0) {
            // MRR-19: every sealed row is gone. Only retention (a head older than the retention window) explains it.
            if ($sid > 0 && !$this->retentionExplains($an['at'])) {
                return $this->broken($out, $sid, 'every sealed row up to ' . $an['where'] . ' is gone and the retention period does not explain it (the audit trail was wiped)');
            }

            return null;
        }
        if ($sid >= (int) $out['first_id']) {
            $r = $this->db->query("SELECT row_hash FROM audit_events WHERE audit_id = $sid");
            $x = $r ? $r->fetch_row() : null;
            if (!$x) {
                return $this->broken($out, $sid, $an['where'] . ' is gone (rows were deleted)');
            }
            if ((string) $x[0] !== $an['hash']) {
                return $this->broken($out, $sid, $an['where'] . ' now has a different hash');
            }
        } elseif (!($sid === 0 && (string) $out['first_prev'] === self::GENESIS) && !$this->retentionExplains($an['at'])) {   // the genesis anchor is satisfied by the chain's true first row
            // M3-10: the head is older than the oldest surviving row and the retention window does not explain that, whether or not the
            // surviving row follows the head's hash (DELETE audit_id <= N leaves exactly that)
            return $this->broken($out, $sid, 'the rows up to ' . $an['where'] . ' are gone and the retention period does not explain it (the oldest rows were removed)');
        }

        return null;
    }

    /**
     * Walk the sealed rows in order, check every link and every hash, then the anchors.
     *
     * @return array{status:'ok'|'broken'|'empty'|'key_unavailable'|'key_changed',checked:int,unsealed:int,late_unsealed:int,broken_at:?int,reason:string,head_id:?int,head_hash:?string,first_id:?int,cause:string}
     */
    public function verify(): array
    {
        $out = ['status' => 'ok', 'checked' => 0, 'unsealed' => 0, 'late_unsealed' => 0, 'broken_at' => null, 'reason' => '', 'head_id' => null, 'head_hash' => null, 'first_id' => null, 'first_prev' => null, 'cause' => ''];
        $mode = PlatformSettings::get($this->db, 'audit_chain_mode', '');
        $keyId = PlatformSettings::get($this->db, 'audit_chain_key_id', '');
        $dbFrom = (int) PlatformSettings::get($this->db, 'audit_chain_hmac_from', '0');
        $dbHead = json_decode(PlatformSettings::get($this->db, 'audit_chain_head', ''), true);
        $dbHead = is_array($dbHead) && isset($dbHead['id'], $dbHead['hash']) ? $dbHead : null;
        $file = $this->readAnchor();
        $fileState = $this->anchorState;

        if ($mode === 'hmac') {
            if ($this->key === null) {
                $out['status'] = 'key_unavailable';
                $out['reason'] = 'The chain was sealed with the settings key and this install has none now.';

                return $out;
            }
            if ($keyId !== '' && $keyId !== $this->keyId()) {
                // MRR-18: audit_chain_key_id lives in the database, so it is not evidence of a key change. If the install's key still verifies
                // a signed head, the key did not change and the stored id was edited.
                if (($file !== null && $this->headSigned($file)) || ($dbHead !== null && $this->headSigned($dbHead))) {
                    return $this->failClosed($out, 'The stored key id was changed in the database, but the settings key still verifies the signed chain head: this is tampering, not a key change.');
                }
                $out['status'] = 'key_changed';
                $out['reason'] = 'The settings key is not the one that sealed the chain; restore the old key to verify it.';

                return $out;
            }
        }
        // MSP-DS-173: the chain mode is not a trust anchor. An install that holds a key seals with HMAC, so a database that says "plain" is
        // a downgrade (someone could rehash edited rows with unkeyed SHA-256) or a chain that was never keyed; either way it is reported.
        if ($mode === 'plain' && $this->key !== null) {
            return $this->failClosed($out, 'The chain is recorded as unkeyed (plain SHA-256) although this install has a settings key; a downgrade would let edited rows be rehashed without the key. If the chain really was sealed before the key existed, use Re-anchor on Administration > Audit trail.');
        }

        $from = $dbFrom;
        if ($this->key !== null) {
            // M3-11/M3-13: with a key the SIGNED anchor file is the root of trust, whatever the database says. No file, no trust: the only
            // exemption is an install that has never sealed a row (nothing to protect yet; its first seal writes the genesis anchor).
            if ($file === null) {
                if ($fileState === 'absent') {
                    if ($this->hasSealedRows() || $dbHead !== null) {
                        return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') is missing although this install has a key and sealed audit entries, so the newest entries cannot be shown to be complete. If you just upgraded from an earlier version, use "Set up the anchor (one time)" on Administration > Audit trail; after restoring a backup or moving the install, use Re-anchor. If neither applies, treat this as tampering.', 'anchor_missing');
                    }
                } else {
                    return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') is ' . ($fileState === 'invalid' ? 'not a valid anchor' : 'unreadable') . ' although this install has a key and a chain that was verified before, so the newest rows cannot be shown to be complete. If you restored a backup or upgraded, use Re-anchor on Administration > Audit trail.');
                }
            } else {
                if (!$this->headSigned($file) || $file['mode'] !== 'hmac') {
                    return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') does not carry a valid key signature.');
                }
                if ($file['from'] !== $dbFrom) {
                    return $this->failClosed($out, 'The start of the keyed segment recorded in the database does not match the signed chain head (the re-anchor setting was changed).');
                }
                $from = $file['from'];   // the layout comes from the signed anchor, never from the database alone
            }
        } elseif ($file === null && $fileState !== 'absent') {
            return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') cannot be read.');
        }

        $out = $this->walk($out, $this->key !== null ? 'hmac' : ($mode === '' ? 'plain' : $mode), $from);
        if ($out['status'] === 'broken') {
            return $out;
        }
        if ($out['checked'] === 0) {
            $out['status'] = 'empty';
        }

        // Anchors: the FILE is compared with the rows first (M3-15), so stripping the database head's signature cannot turn "rows were
        // deleted" into "predates signing"; then the database head (signed, same segment start, same comparison).
        if ($file !== null) {
            $bad = $this->checkAnchor($out, ['id' => $file['id'], 'hash' => $file['hash'], 'at' => $file['at'], 'where' => 'the row the anchor file recorded']);
            if ($bad !== null) {
                return $bad;
            }
        }
        if ($dbHead !== null) {
            if ($this->key !== null) {
                if (!isset($dbHead['mac'])) {
                    return $this->failClosed($out, 'The stored audit chain head carries no key signature (it was rewritten in the database, or it predates signing: use Re-anchor on Administration > Audit trail).');
                }
                if (!$this->headSigned($dbHead) || (string) $dbHead['mode'] !== 'hmac') {
                    return $this->failClosed($out, 'The stored audit chain head does not carry a valid key signature (it was edited in the database).');
                }
                if ((int) $dbHead['from'] !== $from) {
                    return $this->failClosed($out, 'The start of the keyed segment recorded in the database does not match the signed chain head (the re-anchor setting was changed).');
                }
            }
            $bad = $this->checkAnchor($out, ['id' => (int) $dbHead['id'], 'hash' => (string) $dbHead['hash'], 'at' => (string) ($dbHead['at'] ?? ''), 'where' => 'a row that was verified earlier']);
            if ($bad !== null) {
                return $bad;
            }
        }
        $r = $this->db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NULL AND audit_id > ' . (int) $out['head_id']);
        $x = $r ? $r->fetch_row() : null;
        $out['unsealed'] = $x ? (int) $x[0] : 0;
        $r = $this->db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NULL AND audit_id < ' . (int) $out['head_id']);
        $x = $r ? $r->fetch_row() : null;
        $out['late_unsealed'] = $x ? (int) $x[0] : 0;

        return $out;
    }

    /** Remember a successful verification (head and time) and the result, for the page and the next run. @param array<string,mixed> $result */
    public function record(array $result): void
    {
        if ($result['status'] === 'ok' && $result['head_id'] !== null) {
            $this->storeHead((int) $result['head_id'], (string) $result['head_hash'], (int) PlatformSettings::get($this->db, 'audit_chain_hmac_from', '0'));
        }
        PlatformSettings::set($this->db, 'audit_chain_last_verify', json_encode(['at' => gmdate('Y-m-d H:i:s'), 'status' => $result['status'], 'checked' => $result['checked'], 'broken_at' => $result['broken_at'], 'reason' => $result['reason'], 'unsealed' => $result['unsealed'], 'cause' => (string) ($result['cause'] ?? '')]));
    }

    /** The file first (it is the trusted copy), then the database mirror; false when the file could not be written. */
    private function storeHead(int $id, string $hash, int $from): bool
    {
        $head = $this->signHead($id, $hash, $from);
        $ok = $this->writeAnchor($head);   // MSP-DS-173
        PlatformSettings::set($this->db, 'audit_chain_head', json_encode($head));

        return $ok;
    }

    /** What the two anchors say now, for the log line of a re-anchor. */
    public function describeAnchors(): string
    {
        $d = json_decode(PlatformSettings::get($this->db, 'audit_chain_head', ''), true);
        $f = $this->readAnchor();

        return 'database head ' . (is_array($d) && isset($d['id']) ? '#' . (int) $d['id'] . ' ' . substr((string) ($d['hash'] ?? ''), 0, 12) : 'none')
            . ', anchor file ' . ($f !== null ? '#' . $f['id'] . ' ' . substr($f['hash'], 0, 12) : $this->anchorState);
    }

    /** Why the anchor file could not be written, for the administrator (M3-19). */
    private function anchorWriteProblem(): string
    {
        $dir = dirname($this->anchorFile);

        return 'The anchor file ' . $this->anchorFile . ' could not be written (' . (is_dir($dir) ? (is_writable($dir) ? 'the folder is writable, check free space and that the file is not locked' : 'the folder ' . $dir . ' is not writable by the web server user') : 'the folder ' . $dir . ' does not exist and could not be created')
            . '). Fix the owner or permissions of that folder (the web server user must own it) and try again; nothing was changed.';
    }

    /**
     * The supported recovery. Accept the rows as they are now as the new baseline: check that every row still matches its own hash and link (an
     * edited or reordered row is refused, so this cannot launder tampering), then write a new signed head to the file and the database. Use after
     * restoring a database backup, after the anchor file was lost, or to move a chain sealed before the install had a key onto the keyed chain.
     * The caller must have re-checked an administrator's password and must log the returned previous head.
     *
     * - A valid signed anchor file decides the layout (mode and keyed-segment start); the database mode and hmac_from are ignored (M3-14).
     * - Without one the plain and keyed segments are detected by walking (M3-12).
     * - $legacy (the one-time step after upgrading from 26.10.12, M3-16): only when there is no anchor file at all, and the whole chain must
     *   verify as keyed.
     * - The anchor file is written first; when that fails nothing else changes and the reason names the file (M3-19).
     *
     * @return array{ok:bool,reason:string,previous:string,head_id:?int,checked:int,upgraded:bool}
     */
    public function reanchor(bool $legacy = false): array
    {
        $res = ['ok' => false, 'reason' => '', 'previous' => $this->describeAnchors(), 'head_id' => null, 'checked' => 0, 'upgraded' => false];
        $locked = $this->lock(5);
        try {
            $mode = PlatformSettings::get($this->db, 'audit_chain_mode', '');
            $keyId = PlatformSettings::get($this->db, 'audit_chain_key_id', '');
            $file = $this->readAnchor();
            $fileState = $this->anchorState;
            if ($legacy && ($this->key === null || $fileState !== 'absent')) {
                $res['reason'] = $this->key === null ? 'This install has no settings key, so there is no keyed chain to set up an anchor for.' : 'An anchor file already exists (' . $fileState . '). The one-time setup is only for an install that has none; use Re-anchor if you restored a backup.';

                return $res;
            }
            $signed = ($this->key !== null && $file !== null && $this->headSigned($file) && $file['mode'] === 'hmac') ? $file : null;
            if ($this->key === null ? $mode === 'hmac' : ($signed === null && $mode === 'hmac' && $keyId !== '' && $keyId !== $this->keyId())) {
                $res['reason'] = 'The settings key is not the one that sealed the chain; restore the old key before re-anchoring.';

                return $res;
            }
            $out = ['status' => 'ok', 'checked' => 0, 'broken_at' => null, 'reason' => '', 'head_id' => null, 'head_hash' => null, 'first_id' => null, 'first_prev' => null, 'plain_seen' => false, 'boundary' => null];
            if ($this->key === null) {
                $out = $this->walk($out, 'plain', 0);
            } elseif ($signed !== null) {
                $out = $this->walk($out, 'hmac', $signed['from']);
            } elseif ($legacy) {
                $out = $this->walk($out, 'hmac', 0);
            } else {
                $out = $this->walk($out, 'detect', 0);
            }
            if ($out['status'] === 'broken') {
                $res['reason'] = 'The rows themselves do not verify, so there is nothing safe to anchor to: ' . $out['reason'];

                return $res;
            }
            $headId = (int) $out['head_id'];
            $newFrom = 0;
            if ($this->key !== null && $out['checked'] > 0) {
                if ($signed !== null) {
                    $newFrom = $signed['from'] > 0 ? min($signed['from'], $headId + 1) : 0;
                } elseif (!$legacy && $out['plain_seen']) {
                    $newFrom = $out['boundary'] !== null ? (int) $out['boundary'] : $headId + 1;   // rows sealed from now on are keyed
                    $res['upgraded'] = true;
                }
            }
            // the file first: if it cannot be written nothing else is changed, and the administrator is told why
            $head = null;
            if ($this->key !== null) {
                $head = $out['checked'] === 0 ? $this->signHead(0, self::GENESIS, 0) : $this->signHead($headId, (string) $out['head_hash'], $newFrom);
                if (!$this->writeAnchor($head)) {
                    $res['reason'] = $this->anchorWriteProblem();

                    return $res;
                }
            } elseif ($out['checked'] > 0) {
                $head = $this->signHead($headId, (string) $out['head_hash'], 0);
                if (!$this->writeAnchor($head)) {
                    $res['reason'] = $this->anchorWriteProblem();

                    return $res;
                }
            } else {
                @unlink($this->anchorFile);
            }
            if ($this->key !== null) {
                PlatformSettings::set($this->db, 'audit_chain_mode', 'hmac');
                PlatformSettings::set($this->db, 'audit_chain_key_id', $this->keyId());
                PlatformSettings::set($this->db, 'audit_chain_hmac_from', (string) $newFrom);
            } elseif ($mode === '') {
                $this->ensureMode();
            }
            PlatformSettings::set($this->db, 'audit_chain_head', $out['checked'] === 0 || $head === null ? '' : json_encode($head));
            $v = $this->verify();
            $this->record($v);
            $res['ok'] = in_array($v['status'], ['ok', 'empty'], true);
            $res['reason'] = $res['ok'] ? '' : (string) $v['reason'];
            $res['head_id'] = $out['head_id'];
            $res['checked'] = $out['checked'];
        } finally {
            if ($locked) {
                $this->unlock();
            }
        }

        return $res;
    }

    /** @param array<string,mixed> $out @return array<string,mixed> */
    private function broken(array $out, int $id, string $why): array
    {
        $out['status'] = 'broken';
        $out['broken_at'] = $id;
        $out['reason'] = "Audit row #$id: $why.";

        return $out;
    }
}
