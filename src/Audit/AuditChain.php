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
 * key is reported as a downgrade. After restoring an OLDER database backup, delete the anchor file once (the head it holds is newer than the rows).
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

    public function __construct(private \mysqli $db, private ?string $key = null, ?string $anchorFile = null)
    {
        $this->key ??= self::deriveKey();
        $this->anchorFile = $anchorFile ?? (string) ($GLOBALS['config_audit_anchor_file'] ?? dirname(__DIR__, 2) . '/config/audit_chain_head.json');
    }

    /** Keyed (when a key exists) fingerprint of a head, so a stored head cannot be forged without the key. */
    private function headMac(int $id, string $hash): string
    {
        $data = "audit-chain-head|$id|$hash";

        return $this->key !== null ? hash_hmac('sha256', $data, $this->key) : hash('sha256', $data);
    }

    /** @return array{id:int,hash:string,mac:string}|null */
    private function readAnchor(): ?array
    {
        $raw = is_file($this->anchorFile) ? @file_get_contents($this->anchorFile) : false;
        $a = $raw === false ? null : json_decode($raw, true);

        return is_array($a) && isset($a['id'], $a['hash']) ? ['id' => (int) $a['id'], 'hash' => (string) $a['hash'], 'mac' => (string) ($a['mac'] ?? '')] : null;
    }

    private function writeAnchor(int $id, string $hash): void
    {
        $dir = dirname($this->anchorFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = @tempnam($dir, '.anchor.');
        if ($tmp === false) {
            return;   // best effort: never break the audited action or the verify run
        }
        $ok = @file_put_contents($tmp, json_encode(['id' => $id, 'hash' => $hash, 'mac' => $this->headMac($id, $hash), 'at' => gmdate('c')])) !== false && @chmod($tmp, 0600) && @rename($tmp, $this->anchorFile);
        if (!$ok) {
            @unlink($tmp);
        }
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

    /**
     * Seal every unsealed row newer than the newest sealed one, oldest first.
     *
     * @return list<array<string,mixed>> the rows that were sealed (with prev_hash and row_hash), for the sink
     */
    public function seal(int $max = 2000): array
    {
        $this->ensureMode();
        $res = $this->db->query('SELECT audit_id, row_hash FROM audit_events WHERE row_hash IS NOT NULL ORDER BY audit_id DESC LIMIT 1');
        $last = $res ? $res->fetch_assoc() : null;
        $prev = $last ? (string) $last['row_hash'] : self::GENESIS;
        $after = $last ? (int) $last['audit_id'] : 0;
        $res = $this->db->query("SELECT * FROM audit_events WHERE row_hash IS NULL AND audit_id > $after ORDER BY audit_id ASC LIMIT " . max(1, $max));
        $sealed = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $hash = self::rowHash($row, $prev, $this->key);
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
            PlatformSettings::set($this->db, 'audit_chain_key_id', $this->key !== null ? substr(hash('sha256', $this->key), 0, 12) : '');
        }
    }

    /**
     * Walk the sealed rows in order and check every link and every hash.
     *
     * @return array{status:'ok'|'broken'|'empty'|'key_unavailable'|'key_changed',checked:int,unsealed:int,late_unsealed:int,broken_at:?int,reason:string,head_id:?int,head_hash:?string,first_id:?int}
     */
    public function verify(): array
    {
        $out = ['status' => 'ok', 'checked' => 0, 'unsealed' => 0, 'late_unsealed' => 0, 'broken_at' => null, 'reason' => '', 'head_id' => null, 'head_hash' => null, 'first_id' => null];
        $mode = PlatformSettings::get($this->db, 'audit_chain_mode', '');
        $keyId = PlatformSettings::get($this->db, 'audit_chain_key_id', '');
        if ($mode === 'hmac') {
            if ($this->key === null) {
                $out['status'] = 'key_unavailable';
                $out['reason'] = 'The chain was sealed with the settings key and this install has none now.';

                return $out;
            }
            if ($keyId !== '' && $keyId !== substr(hash('sha256', $this->key), 0, 12)) {
                $out['status'] = 'key_changed';
                $out['reason'] = 'The settings key is not the one that sealed the chain; restore the old key to verify it.';

                return $out;
            }
        }
        // MSP-DS-173: the chain mode is not a trust anchor. An install that holds a key seals with HMAC, so a database that says "plain" is
        // a downgrade (someone could rehash edited rows with unkeyed SHA-256) or a chain that was never keyed; either way it is reported.
        if ($mode === 'plain' && $this->key !== null) {
            $out['status'] = 'broken';
            $out['reason'] = 'The chain is recorded as unkeyed (plain SHA-256) although this install has a settings key; a downgrade would let edited rows be rehashed without the key.';

            return $out;
        }
        $key = $mode === 'plain' ? null : $this->key;

        $prev = null;
        $afterId = 0;
        $first = true;
        $lastSealedId = 0;
        while (true) {
            $res = $this->db->query("SELECT * FROM audit_events WHERE row_hash IS NOT NULL AND audit_id > $afterId ORDER BY audit_id ASC LIMIT 2000");
            $n = 0;
            while ($res && ($row = $res->fetch_assoc())) {
                $n++;
                $id = (int) $row['audit_id'];
                $afterId = $id;
                $lastSealedId = $id;
                if ($first) {
                    $out['first_id'] = $id;
                    $first = false;       // the oldest surviving row is the trust start: retention may have removed what came before it
                } elseif ($row['prev_hash'] !== $prev) {
                    return $this->broken($out, $id, 'its prev_hash does not match the row before it (a row was removed, inserted or reordered)');
                }
                if (self::rowHash($row, (string) $row['prev_hash'], $key) !== $row['row_hash']) {
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
        if ($out['checked'] === 0) {
            $out['status'] = 'empty';
        }
        // The head seen at the last verification must still be there (or have been pruned from the old end); otherwise rows were cut off.
        // MSP-DS-173: two anchors. The database copy (audit_chain_head, now carrying a keyed mac) and the FILE copy written next to the app
        // (config/audit_chain_head.json), which a database-only writer cannot move. Either one pointing at a row that is gone or different is a cut.
        $anchors = [];
        $stored = json_decode(PlatformSettings::get($this->db, 'audit_chain_head', ''), true);
        if (is_array($stored) && isset($stored['id'], $stored['hash'])) {
            if (isset($stored['mac']) && $this->key !== null && !hash_equals($this->headMac((int) $stored['id'], (string) $stored['hash']), (string) $stored['mac'])) {
                $out['status'] = 'broken';
                $out['reason'] = 'The stored audit chain head does not carry a valid key signature (it was edited in the database).';

                return $out;
            }
            $anchors[] = ['id' => (int) $stored['id'], 'hash' => (string) $stored['hash'], 'where' => 'a row that was verified earlier'];
        }
        $file = $this->readAnchor();
        if ($file !== null) {
            if (!hash_equals($this->headMac($file['id'], $file['hash']), $file['mac'])) {
                $out['status'] = 'broken';
                $out['reason'] = 'The audit chain anchor file (' . basename($this->anchorFile) . ') does not carry a valid key signature.';

                return $out;
            }
            $anchors[] = ['id' => $file['id'], 'hash' => $file['hash'], 'where' => 'the row the anchor file recorded'];
        }
        if ($out['checked'] > 0) {
            foreach ($anchors as $an) {
                $sid = $an['id'];
                if ($sid >= (int) $out['first_id']) {
                    $r = $this->db->query("SELECT row_hash FROM audit_events WHERE audit_id = $sid");
                    $x = $r ? $r->fetch_row() : null;
                    if (!$x) {
                        return $this->broken($out, $sid, $an['where'] . ' is gone (rows were deleted)');
                    }
                    if ((string) $x[0] !== $an['hash']) {
                        return $this->broken($out, $sid, $an['where'] . ' now has a different hash');
                    }
                }
            }
        }
        $r = $this->db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NULL AND audit_id > ' . $lastSealedId);
        $x = $r ? $r->fetch_row() : null;
        $out['unsealed'] = $x ? (int) $x[0] : 0;
        $r = $this->db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NULL AND audit_id < ' . $lastSealedId);
        $x = $r ? $r->fetch_row() : null;
        $out['late_unsealed'] = $x ? (int) $x[0] : 0;

        return $out;
    }

    /** Remember a successful verification (head and time) and the result, for the page and the next run. @param array<string,mixed> $result */
    public function record(array $result): void
    {
        if ($result['status'] === 'ok' && $result['head_id'] !== null) {
            PlatformSettings::set($this->db, 'audit_chain_head', json_encode(['id' => $result['head_id'], 'hash' => $result['head_hash'], 'mac' => $this->headMac((int) $result['head_id'], (string) $result['head_hash'])]));
            $this->writeAnchor((int) $result['head_id'], (string) $result['head_hash']);   // MSP-DS-173
        }
        PlatformSettings::set($this->db, 'audit_chain_last_verify', json_encode(['at' => gmdate('Y-m-d H:i:s'), 'status' => $result['status'], 'checked' => $result['checked'], 'broken_at' => $result['broken_at'], 'reason' => $result['reason'], 'unsealed' => $result['unsealed']]));
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
