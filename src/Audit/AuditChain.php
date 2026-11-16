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
 * key is reported as a downgrade. With a key the anchors fail closed (a missing, unreadable or unsigned one is a failure; a full wipe is a failure unless the
 * retention period explains it). After restoring a database backup, or to move a plain chain onto the key, use reanchor() (Administration > Audit trail).
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
    private int $retentionDays;
    /** @var 'ok'|'absent'|'unreadable'|'invalid' state of the anchor file at the last readAnchor() */
    private string $anchorState = 'absent';

    public function __construct(private \mysqli $db, private ?string $key = null, ?string $anchorFile = null, ?int $retentionDays = null)
    {
        $this->key ??= self::deriveKey();
        $this->anchorFile = $anchorFile ?? (string) ($GLOBALS['config_audit_anchor_file'] ?? dirname(__DIR__, 2) . '/config/audit_chain_head.json');
        $this->retentionDays = $retentionDays ?? (int) ($GLOBALS['config_audit_retention_days'] ?? 0);   // 0 = keep forever: nothing may legitimately vanish
    }

    /** Short fingerprint of the current key (what audit_chain_key_id holds); '' without a key. */
    private function keyId(): string
    {
        return $this->key !== null ? substr(hash('sha256', $this->key), 0, 12) : '';
    }

    /**
     * MSP-DS-173 / MRR-18: keyed signature of a head. It covers the key id (so the key id cannot be swapped in the database without the
     * signature failing), the time it was recorded (the retention test uses it) and the start of the HMAC segment (re-anchored plain chains).
     */
    private function headMac(int $id, string $hash, string $at, string $kid, int $from): string
    {
        $data = "audit-chain-head|$id|$hash|$at|$kid|$from";

        return $this->key !== null ? hash_hmac('sha256', $data, $this->key) : hash('sha256', $data);
    }

    /** @return array{id:int,hash:string,at:string,kid:string,from:int,mac:string} */
    private function signHead(int $id, string $hash, int $from): array
    {
        $at = gmdate('c');
        $kid = $this->keyId();

        return ['id' => $id, 'hash' => $hash, 'at' => $at, 'kid' => $kid, 'from' => $from, 'mac' => $this->headMac($id, $hash, $at, $kid, $from)];
    }

    /** @param array<string,mixed> $h */
    private function headSigned(array $h): bool
    {
        if (!isset($h['id'], $h['hash'], $h['mac'], $h['at'], $h['kid'], $h['from'])) {
            return false;
        }

        return hash_equals($this->headMac((int) $h['id'], (string) $h['hash'], (string) $h['at'], (string) $h['kid'], (int) $h['from']), (string) $h['mac'])
            && (string) $h['kid'] === $this->keyId();
    }

    /** @return array{id:int,hash:string,at:string,kid:string,from:int,mac:string}|null  (sets $this->anchorState) */
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

        return ['id' => (int) $a['id'], 'hash' => (string) $a['hash'], 'at' => (string) ($a['at'] ?? ''), 'kid' => (string) ($a['kid'] ?? ''), 'from' => (int) ($a['from'] ?? 0), 'mac' => (string) ($a['mac'] ?? '')];
    }

    /** @param array{id:int,hash:string,at:string,kid:string,from:int,mac:string} $head */
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

    /** Record how the chain is keyed the first time it is used, so the verifier can tell "key changed" from "tampered". */
    private function ensureMode(): void
    {
        if (PlatformSettings::get($this->db, 'audit_chain_mode', '') === '') {
            PlatformSettings::set($this->db, 'audit_chain_mode', $this->key !== null ? 'hmac' : 'plain');
            PlatformSettings::set($this->db, 'audit_chain_key_id', $this->keyId());
        }
    }

    /**
     * Walk the sealed rows in order and check every link and every hash (no anchors).
     *
     * @param array<string,mixed> $out
     * @return array<string,mixed> $out, with status 'broken' on the first bad row
     */
    private function walk(array $out, string $mode, int $from): array
    {
        $prev = null;
        $afterId = 0;
        $first = true;
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
                // rows before a re-anchored plain chain's keyed segment were sealed unkeyed; the first keyed row's prev_hash binds them
                $rowKey = ($mode === 'plain' || $id < $from) ? null : $this->key;
                if (self::rowHash($row, (string) $row['prev_hash'], $rowKey) !== $row['row_hash']) {
                    return $this->broken($out, $id, 'its contents no longer match its hash (the row was edited)');
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

    /** True when rows up to a head recorded at $at may legitimately be gone: retention is on and the head is older than its window. */
    private function retentionExplains(string $at): bool
    {
        $t = strtotime($at);

        return $this->retentionDays > 0 && $t !== false && $t < time() - $this->retentionDays * 86400;
    }

    /** @param array<string,mixed> $out @return array<string,mixed> */
    private function failClosed(array $out, string $why): array
    {
        $out['status'] = 'broken';
        $out['reason'] = $why;

        return $out;
    }

    /**
     * Walk the sealed rows in order, check every link and every hash, then the anchors.
     *
     * @return array{status:'ok'|'broken'|'empty'|'key_unavailable'|'key_changed',checked:int,unsealed:int,late_unsealed:int,broken_at:?int,reason:string,head_id:?int,head_hash:?string,first_id:?int}
     */
    public function verify(): array
    {
        $out = ['status' => 'ok', 'checked' => 0, 'unsealed' => 0, 'late_unsealed' => 0, 'broken_at' => null, 'reason' => '', 'head_id' => null, 'head_hash' => null, 'first_id' => null, 'first_prev' => null];
        $mode = PlatformSettings::get($this->db, 'audit_chain_mode', '');
        $keyId = PlatformSettings::get($this->db, 'audit_chain_key_id', '');
        $from = (int) PlatformSettings::get($this->db, 'audit_chain_hmac_from', '0');
        $dbHead = json_decode(PlatformSettings::get($this->db, 'audit_chain_head', ''), true);
        $dbHead = is_array($dbHead) && isset($dbHead['id'], $dbHead['hash']) ? $dbHead : null;
        $file = $this->readAnchor();
        $fileState = $this->anchorState;
        $last = json_decode(PlatformSettings::get($this->db, 'audit_chain_last_verify', ''), true);
        $hadHead = is_array($last) && ($last['status'] ?? '') === 'ok' && (int) ($last['checked'] ?? 0) > 0;

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

        $out = $this->walk($out, $mode, $from);
        if ($out['status'] === 'broken') {
            return $out;
        }
        if ($out['checked'] === 0) {
            $out['status'] = 'empty';
        }

        // Anchors: two copies of the last verified head, in the database and in a FILE next to the app that a database-only writer cannot
        // move. With a key both must be signed; a missing signature or a missing/unreadable file is a failure (MRR-20), not something to skip.
        if ($this->key !== null) {
            if ($dbHead !== null) {
                if (!isset($dbHead['mac'])) {
                    return $this->failClosed($out, 'The stored audit chain head carries no key signature (it was rewritten in the database, or it predates signing: use Re-anchor on Administration > Audit trail).');
                }
                if (!$this->headSigned($dbHead)) {
                    return $this->failClosed($out, 'The stored audit chain head does not carry a valid key signature (it was edited in the database).');
                }
            }
            if ($file === null && ($fileState !== 'absent' || $dbHead !== null || $hadHead)) {
                $what = $fileState === 'absent' ? 'missing' : ($fileState === 'invalid' ? 'not a valid anchor' : 'unreadable');
                return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') is ' . $what . ' although this install has a key and a chain that was verified before, so the newest rows cannot be shown to be complete. If you restored a backup or upgraded, use Re-anchor on Administration > Audit trail.');
            }
            if ($file !== null && !$this->headSigned($file)) {
                return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') does not carry a valid key signature.');
            }
        } elseif ($file === null && $fileState !== 'absent') {
            return $this->failClosed($out, 'The audit chain anchor file (' . basename($this->anchorFile) . ') cannot be read.');
        }
        $anchors = [];
        if ($dbHead !== null) {
            $anchors[] = ['id' => (int) $dbHead['id'], 'hash' => (string) $dbHead['hash'], 'at' => (string) ($dbHead['at'] ?? ''), 'from' => (int) ($dbHead['from'] ?? 0), 'where' => 'a row that was verified earlier'];
        }
        if ($file !== null) {
            $anchors[] = ['id' => $file['id'], 'hash' => $file['hash'], 'at' => $file['at'], 'from' => $file['from'], 'where' => 'the row the anchor file recorded'];
        }
        foreach ($anchors as $an) {
            if ($this->key !== null && $an['from'] !== $from) {
                return $this->failClosed($out, 'The start of the keyed segment recorded in the database does not match the signed chain head (the re-anchor setting was changed).');
            }
            $sid = $an['id'];
            if ($out['checked'] === 0) {
                // MRR-19: every sealed row is gone. Only retention (a head older than the retention window) explains it.
                if ($sid > 0 && !$this->retentionExplains($an['at'])) {
                    return $this->broken($out, $sid, 'every sealed row up to ' . $an['where'] . ' is gone and the retention period does not explain it (the audit trail was wiped)');
                }
                continue;
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
            } elseif ((string) $out['first_prev'] !== $an['hash'] && !$this->retentionExplains($an['at'])) {
                // MRR-19: the head is older than the oldest surviving row, and that row does not follow it
                return $this->broken($out, $sid, 'the rows up to ' . $an['where'] . ' are gone and the retention period does not explain it (the oldest rows were removed)');
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
            $this->storeHead((int) $result['head_id'], (string) $result['head_hash']);
        }
        PlatformSettings::set($this->db, 'audit_chain_last_verify', json_encode(['at' => gmdate('Y-m-d H:i:s'), 'status' => $result['status'], 'checked' => $result['checked'], 'broken_at' => $result['broken_at'], 'reason' => $result['reason'], 'unsealed' => $result['unsealed']]));
    }

    private function storeHead(int $id, string $hash): bool
    {
        $head = $this->signHead($id, $hash, (int) PlatformSettings::get($this->db, 'audit_chain_hmac_from', '0'));
        PlatformSettings::set($this->db, 'audit_chain_head', json_encode($head));

        return $this->writeAnchor($head);   // MSP-DS-173
    }

    /** What the two anchors say now, for the log line of a re-anchor. */
    public function describeAnchors(): string
    {
        $d = json_decode(PlatformSettings::get($this->db, 'audit_chain_head', ''), true);
        $f = $this->readAnchor();

        return 'database head ' . (is_array($d) && isset($d['id']) ? '#' . (int) $d['id'] . ' ' . substr((string) ($d['hash'] ?? ''), 0, 12) : 'none')
            . ', anchor file ' . ($f !== null ? '#' . $f['id'] . ' ' . substr($f['hash'], 0, 12) : $this->anchorState);
    }

    /**
     * MRR-21: the supported recovery. Accept the rows as they are now as the new baseline: check that every row still matches its own hash and
     * link (an edited or reordered row is refused, so this cannot launder tampering), then write a new signed head to the database and the file.
     * Use after restoring a database backup, after the anchor file was lost, or to move a chain sealed before the install had a key onto
     * the keyed chain. The caller must have re-checked an administrator's password and must log the returned previous head.
     *
     * @return array{ok:bool,reason:string,previous:string,head_id:?int,checked:int,upgraded:bool}
     */
    public function reanchor(): array
    {
        $res = ['ok' => false, 'reason' => '', 'previous' => $this->describeAnchors(), 'head_id' => null, 'checked' => 0, 'upgraded' => false];
        $locked = $this->lock(5);
        try {
            $mode = PlatformSettings::get($this->db, 'audit_chain_mode', '');
            $keyId = PlatformSettings::get($this->db, 'audit_chain_key_id', '');
            if ($mode === 'hmac' && ($this->key === null || ($keyId !== '' && $keyId !== $this->keyId()))) {
                $res['reason'] = 'The settings key is not the one that sealed the chain; restore the old key before re-anchoring.';

                return $res;
            }
            $out = ['status' => 'ok', 'checked' => 0, 'broken_at' => null, 'reason' => '', 'head_id' => null, 'head_hash' => null, 'first_id' => null, 'first_prev' => null];
            $out = $this->walk($out, $mode === '' ? ($this->key !== null ? 'hmac' : 'plain') : $mode, (int) PlatformSettings::get($this->db, 'audit_chain_hmac_from', '0'));
            if ($out['status'] === 'broken') {
                $res['reason'] = 'The rows themselves do not verify, so there is nothing safe to anchor to: ' . $out['reason'];

                return $res;
            }
            if ($mode === 'plain' && $this->key !== null) {
                // upgrade: the plain rows stay as they are; rows sealed from now on are keyed
                PlatformSettings::set($this->db, 'audit_chain_mode', 'hmac');
                PlatformSettings::set($this->db, 'audit_chain_key_id', $this->keyId());
                PlatformSettings::set($this->db, 'audit_chain_hmac_from', (string) ((int) $out['head_id'] + 1));
                $res['upgraded'] = true;
            } elseif ($mode === '') {
                $this->ensureMode();
            }
            if ($out['checked'] === 0) {
                PlatformSettings::set($this->db, 'audit_chain_head', '');
                @unlink($this->anchorFile);
            } else {
                $this->storeHead((int) $out['head_id'], (string) $out['head_hash']);
            }
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
