<?php

/*
 * Shared date-range helpers (RivetCore \RivetCore\Ui\DateRange). Used by includes/filter_header.php (every list/report
 * page that has a date filter) and by the Service Desk pages that filter on a chosen ticket date column.
 *
 * Timezone: the app's configured timezone (settings.config_timezone, loaded into $_SESSION['session_timezone'] by
 * includes/inc_set_timezone.php, which also sets PHP's default timezone and the MySQL session offset), so "today" here is
 * the same "today" the rest of the app and the database use. Week start: Monday (the app has no week-start setting).
 *
 * Request vocabulary (unchanged from the legacy filter): canned_date = preset id | custom | alltime, dtf / dtt = explicit
 * dates (Y-m-d) used only with canned_date=custom (or with no canned_date at all, as old links do).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use RivetCore\Ui\DateRange;

/** The app's timezone. */
function dateRangeTimezone(): DateTimeZone
{
    $name = $_SESSION['session_timezone'] ?? date_default_timezone_get();
    try {
        return new DateTimeZone((string) $name);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

/** First day of the week, 1 = Monday .. 7 = Sunday. */
function dateRangeWeekStart(): int
{
    return 1;
}

/** Resolve one preset / custom pair in the app timezone. */
function dateRangeResolve(string $preset, ?string $from = null, ?string $to = null): DateRange
{
    return DateRange::resolve($preset, $from, $to, null, dateRangeTimezone(), dateRangeWeekStart());
}

/**
 * The range a request asks for. Never throws and never warns on junk input: unknown presets and unparseable custom dates
 * fall back to all time; reversed custom dates are swapped; a custom range with only one end is open-ended.
 *
 * @param array<string,mixed> $get         usually $_GET
 * @param string              $defaultPreset what an empty request means (a page's own default)
 */
function dateRangeFromRequest(array $get, string $defaultPreset = 'alltime'): DateRange
{
    $str = static fn($k): string => isset($get[$k]) && is_string($get[$k]) ? trim($get[$k]) : '';
    $preset = strtolower($str('canned_date'));
    $from = $str('dtf');
    $to = $str('dtt');

    if ($preset === '') {
        // Old links carry only dtf/dtt; a bare request means the page default.
        $preset = ($from !== '' || $to !== '') ? 'custom' : $defaultPreset;
    }
    if ($preset !== 'custom' && !DateRange::isPreset($preset)) {
        $preset = 'alltime';
    }

    return dateRangeResolve($preset, $from, $to);
}

/**
 * Sargable SQL for "$column falls inside the range": `col >= 'from 00:00:00' AND col < 'day after to 00:00:00'`, so an
 * index on the column can be used (unlike DATE(col) BETWEEN ...). An all-time range adds no restriction (`1=1`), so
 * rows with a NULL date still show. $column must be a plain (optionally table-qualified) identifier.
 */
function dateRangeSqlBetween(string $column, DateRange $r): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
        throw new InvalidArgumentException('Invalid column name for a date range');
    }
    if ($r->isAllTime()) {
        return '1=1';
    }
    [$a, $b] = $r->sqlBounds(); // validated Y-m-d H:i:s strings
    return "($column >= '$a' AND $column < '$b')";
}

/**
 * The canonical GET params for a range (presets carry only canned_date so saved links stay rolling; custom carries dates).
 *
 * @return array<string,string>
 */
function dateRangeUrlParams(DateRange $r): array
{
    return $r->toQuery();
}

/** Short human dates for a range, e.g. "Sep 6 – Oct 5" (years added when they are not the current year). '' for all time. */
function dateRangeDisplayDates(DateRange $r): string
{
    if ($r->isAllTime()) {
        return '';
    }
    $tz = dateRangeTimezone();
    $thisYear = (new DateTimeImmutable('now', $tz))->format('Y');
    $a = new DateTimeImmutable($r->from(), new DateTimeZone('UTC'));
    $b = new DateTimeImmutable($r->to(), new DateTimeZone('UTC'));
    $fmt = static fn(DateTimeImmutable $d, bool $year): string => $d->format($year ? 'M j, Y' : 'M j');
    if ($r->to() === DateRange::MAX_DATE && $r->from() !== DateRange::MIN_DATE) {
        return 'From ' . $fmt($a, true);
    }
    if ($r->from() === DateRange::MIN_DATE && $r->to() !== DateRange::MAX_DATE) {
        return 'Up to ' . $fmt($b, true);
    }
    $needYear = $a->format('Y') !== $thisYear || $b->format('Y') !== $thisYear;
    if ($r->from() === $r->to()) {
        return $fmt($a, $needYear);
    }
    return $fmt($a, $needYear) . ' – ' . $fmt($b, $needYear);
}

/** The ticket date columns the Service Desk can filter on (`datefield` GET param => column). */
function ticketDateFields(): array
{
    return [
        'created'  => ['label' => 'Created',          'column' => 'ticket_created_at'],
        'updated'  => ['label' => 'Updated',          'column' => 'ticket_updated_at'],
        'resolved' => ['label' => 'Resolved',         'column' => 'ticket_resolved_at'],
        'closed'   => ['label' => 'Closed',           'column' => 'ticket_closed_at'],
        'due'      => ['label' => 'SLA resolution due', 'column' => 'ticket_sla_resolution_due'],
        'duedate'  => ['label' => 'Due date',         'column' => 'ticket_due_at'],
    ];
}

/** Whitelisted datefield key from a request ('created' for anything unknown). */
function ticketDateFieldFromRequest(array $get): string
{
    $k = isset($get['datefield']) && is_string($get['datefield']) ? strtolower(trim($get['datefield'])) : 'created';
    return isset(ticketDateFields()[$k]) ? $k : 'created';
}
