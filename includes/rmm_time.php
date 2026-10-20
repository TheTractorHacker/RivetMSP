<?php

/*
 * RMM / Endpoint agent / admin wrappers over the shared helper in includes/rivet_time.php (identical in RivetIT and RivetMSP):
 * the same rules, with the boolean "is it UTC-stored" argument the RMM pages use (true = Core / endpoint_agent_* / epoch, false = the edition's
 * NOW() columns, which are already company time). Plain text out; the caller escapes. Empty, zero and invalid input give $empty.
 *
 * STORAGE TRAP. Core (RivetCore, endpoint_agent_*, rmm_* read models) writes UTC; rmm_alerts, rmm_remote_sessions, rmm_script_runs, rmm_sync_log,
 * asset_rmm_links, audit_events are written with NOW() under the company zone. Convert the first, only format the second.
 */
require_once __DIR__ . '/rivet_time.php';

/** The company time zone as an object. */
function rivetRmmTimeZone(): DateTimeZone
{
    return new DateTimeZone(rivetCompanyTimezone());
}

/** @param mixed $v @return string how rivetFormatTime() should read it */
function rivetRmmStoredAs($v, bool $utc): string
{
    return is_int($v) || (is_string($v) && preg_match('/^\d{9,11}$/', trim($v)) === 1) ? 'epoch' : ($utc ? 'utc' : 'local');
}

/** A stored value as a DateTimeImmutable in the company zone, or null. */
function rivetRmmTimeParse($v, bool $utc = true): ?DateTimeImmutable
{
    if ($v instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($v)->setTimezone(rivetRmmTimeZone());
    }
    if (!is_int($v) && !is_string($v)) {
        return null;
    }
    $d = rivetTimeParse($v, rivetRmmStoredAs($v, $utc));

    return $d === null ? null : $d->setTimezone(rivetRmmTimeZone());
}

/** "2026-10-20 10:42 CDT" ($zone false drops the label; $fmt is a date() format). */
function rivetRmmTime($v, bool $utc = true, bool $zone = true, string $fmt = 'Y-m-d H:i', string $empty = ''): string
{
    if ($v instanceof DateTimeInterface) {
        $v = $v->getTimestamp();
    }
    if (!is_int($v) && !is_string($v)) {
        return $empty;
    }
    $s = rivetFormatTime($v, rivetRmmStoredAs($v, $utc), $zone, $fmt);

    return $s === '' ? $empty : $s;
}

/** Date only in the company zone ("2026-10-20"), no label. */
function rivetRmmDate($v, bool $utc = true, string $empty = ''): string
{
    return rivetRmmTime($v, $utc, false, 'Y-m-d', $empty);
}

/** A <time> element (markup): the datetime attribute is the UTC instant, the text is company time with its label. $empty is markup. */
function rivetRmmTimeTag($v, bool $utc = true, string $empty = '', string $fmt = 'Y-m-d H:i'): string
{
    $d = rivetRmmTimeParse($v, $utc);
    if ($d === null) {
        return $empty;
    }

    return '<time datetime="' . htmlspecialchars(rivetTimeIso($d->format('Y-m-d H:i:s'), 'local', $d->getTimezone()->getName()), ENT_QUOTES) . '">'
        . htmlspecialchars(rivetRmmTime($d->format('Y-m-d H:i:s'), false, true, $fmt), ENT_QUOTES) . '</time>';
}

/** A value as the zone-less text of a datetime-local input in company time ("2026-10-20T10:42"), or ''. */
function rivetRmmTimeInput($v, bool $utc = true): string
{
    $d = rivetRmmTimeParse($v, $utc);

    return $d === null ? '' : $d->format('Y-m-d\TH:i');
}

/** A datetime-local value typed in company time as an UTC instant (RFC 3339 'Z'), or null. */
function rivetRmmTimeFromInput(string $local): ?string
{
    $local = trim($local);
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $local) !== 1) {
        return null;
    }
    $iso = rivetTimeIso(str_replace('T', ' ', $local), 'local');

    return $iso === '' ? null : $iso;
}
