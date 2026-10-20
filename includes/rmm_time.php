<?php

/*
 * One place that turns a stored timestamp into the COMPANY's time for the RMM / Endpoint agent / admin screens.
 *
 * The company time zone is the application's configured one (settings.config_timezone, loaded as $config_timezone / the session copy);
 * the server's PHP zone is only the fallback. DST is handled by DateTimeZone, never by a fixed offset.
 *
 * STORAGE TRAP. Two kinds of value reach these pages:
 *   - UTC: RivetCore and the endpoint_agent_* tables (UTC_TIMESTAMP(), gmdate, RFC 3339 'Z' strings, epoch ints). Convert them.
 *   - company-local: the edition's own tables written with NOW() (rmm_alerts, rmm_remote_sessions, ...). They are ALREADY company time:
 *     pass $utc = false and they are only formatted. Never convert twice, never label a local value "UTC".
 * A string with an explicit offset ('Z', '+02:00') or an int epoch is unambiguous and ignores $utc.
 *
 * All functions are null / empty / zero-date / garbage safe: they return $empty. They return PLAIN text; the caller escapes.
 */

/** The company time zone (a valid DateTimeZone): config_timezone, then the session copy, then PHP's default, then UTC. */
function rivetRmmTimeZone(): DateTimeZone
{
    foreach ([$GLOBALS['config_timezone'] ?? null, $_SESSION['session_timezone'] ?? null, date_default_timezone_get()] as $name) {
        if (is_string($name) && $name !== '') {
            try {
                return new DateTimeZone($name);
            } catch (\Throwable $e) {
                // not a zone name: try the next source
            }
        }
    }

    return new DateTimeZone('UTC');
}

/**
 * A stored value as a DateTimeImmutable in the company zone, or null.
 *
 * @param mixed $v    SQL datetime, RFC 3339, int/numeric epoch, DateTimeInterface, null
 * @param bool  $utc  how a zone-less string is stored: true = UTC (RivetCore, endpoint_agent_*), false = company-local (NOW())
 */
function rivetRmmTimeParse($v, bool $utc = true): ?DateTimeImmutable
{
    $tz = rivetRmmTimeZone();
    try {
        if ($v instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($v)->setTimezone($tz);
        }
        if (is_int($v) || (is_string($v) && preg_match('/^\d{9,11}$/', trim($v)))) {
            $e = (int) $v;

            return $e > 0 ? (new DateTimeImmutable('@' . $e))->setTimezone($tz) : null;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '' || str_starts_with($s, '0000-00-00') || !preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return null;
        }
        $explicit = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $s);
        $d = new DateTimeImmutable($s, $explicit ? null : new DateTimeZone($utc ? 'UTC' : $tz->getName()));

        return $d->setTimezone($tz);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * "2026-10-20 10:42 CDT". $fmt is a date() format without the zone; $zone appends the short label ("CDT", "CST", "GMT+2").
 */
function rivetRmmTime($v, bool $utc = true, bool $zone = true, string $fmt = 'Y-m-d H:i', string $empty = ''): string
{
    $d = rivetRmmTimeParse($v, $utc);

    return $d === null ? $empty : $d->format($fmt) . ($zone ? ' ' . rivetRmmTimeAbbr($d) : '');
}

/** Date only in the company zone ("2026-10-20"), no label. */
function rivetRmmDate($v, bool $utc = true, string $empty = ''): string
{
    return rivetRmmTime($v, $utc, false, 'Y-m-d', $empty);
}

/** The short zone label of an instant: "CDT" in summer, "CST" in winter; "+03" style zones become "GMT+3". */
function rivetRmmTimeAbbr(DateTimeImmutable $d): string
{
    $a = $d->format('T');
    if (preg_match('/^[+-]\d{2}(\d{2})?$/', $a)) {
        $o = $d->getOffset();

        return $o === 0 ? 'UTC' : 'GMT' . ($o < 0 ? '-' : '+') . (int) (abs($o) / 3600) . (abs($o) % 3600 ? sprintf(':%02d', (abs($o) % 3600) / 60) : '');
    }

    return $a;
}

/** A <time> element: datetime attribute in UTC (machine), text in company time with the zone label. Returns markup; empty -> $empty (markup). */
function rivetRmmTimeTag($v, bool $utc = true, string $empty = '', string $fmt = 'Y-m-d H:i'): string
{
    $d = rivetRmmTimeParse($v, $utc);
    if ($d === null) {
        return $empty;
    }

    return '<time datetime="' . htmlspecialchars($d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), ENT_QUOTES) . '">'
        . htmlspecialchars($d->format($fmt) . ' ' . rivetRmmTimeAbbr($d), ENT_QUOTES) . '</time>';
}

/** A company-time value in the zone-less form of a datetime-local input ("2026-10-20T10:42"), or ''. */
function rivetRmmTimeInput($v, bool $utc = true): string
{
    $d = rivetRmmTimeParse($v, $utc);

    return $d === null ? '' : $d->format('Y-m-d\TH:i');
}

/** A datetime-local value typed in company time as an UTC instant (RFC 3339 'Z'), or null. */
function rivetRmmTimeFromInput(string $local): ?string
{
    $local = trim($local);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $local)) {
        return null;
    }
    try {
        return (new DateTimeImmutable($local, rivetRmmTimeZone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (\Throwable $e) {
        return null;
    }
}
