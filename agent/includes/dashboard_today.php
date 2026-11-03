<?php

/*
 * Dashboard "Today" board: what is waiting on a person today, ranked by money, one click from action.
 *
 * Money view (accounting module on, and a role that already sees the Financial section):
 *   Ready to bill   closed, billable, not yet invoiced tickets, estimated as logged time x the client's hourly rate (an estimate, labelled as one)
 *   Overdue         unpaid invoices past their due date: invoice amount minus payments (actual)
 *   Quotes waiting  sent or viewed quotes that have not expired (potential, not income)
 * Everyone else gets the work view: tickets waiting on me, past due, unassigned.
 *
 * Every figure honours $access_permission_query (clients the user may see). Styles: css/dashboard_today.css (edition-owned).
 * Expects: $mysqli, $access_permission_query, $session_user_id, $session_user_role, $currency_format, $session_company_currency,
 * $config_module_enable_accounting.
 */

$td_money = !empty($config_module_enable_accounting) && ($session_user_role == 1 || $session_user_role == 3);
$td_scope = $access_permission_query ?? '';
$td_fmt = static fn($v): string => numfmt_format_currency($currency_format, (float) $v, "$session_company_currency");
$td_h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$td_days = static function ($date): int {
    $ts = strtotime((string) $date);
    return $ts ? max(0, (int) floor((time() - $ts) / 86400)) : 0;
};

if ($td_money) {
    // Ready to bill: per ticket, hours worked x client rate. TIME_TO_SEC because replies store time worked as TIME.
    $td_unbilled = ['n' => 0, 'v' => 0.0, 'rows' => []];
    $q = mysqli_query($mysqli, "SELECT t.ticket_client_id, clients.client_name AS client_name, COUNT(*) AS n, SUM(t.hours * t.rate) AS v
        FROM (SELECT ticket_id, ticket_client_id, COALESCE(SUM(TIME_TO_SEC(ticket_reply_time_worked)), 0) / 3600 AS hours, COALESCE(MAX(clients.client_rate), 0) AS rate
              FROM tickets
              LEFT JOIN clients ON clients.client_id = ticket_client_id
              LEFT JOIN ticket_replies ON ticket_reply_ticket_id = ticket_id AND ticket_reply_archived_at IS NULL
              WHERE ticket_closed_at IS NOT NULL AND ticket_billable = 1 AND ticket_invoice_id = 0 AND ticket_archived_at IS NULL $td_scope
              GROUP BY ticket_id, ticket_client_id) t
        LEFT JOIN clients ON clients.client_id = t.ticket_client_id
        GROUP BY t.ticket_client_id, clients.client_name ORDER BY v DESC, n DESC");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $td_unbilled['n'] += (int) $r['n'];
        $td_unbilled['v'] += (float) $r['v'];
        if (count($td_unbilled['rows']) < 3) {
            $td_unbilled['rows'][] = ['label' => (string) $r['client_name'], 'meta' => (int) $r['n'] . ((int) $r['n'] === 1 ? ' ticket' : ' tickets'), 'amount' => (float) $r['v'], 'href' => 'reports/tickets_unbilled.php'];
        }
    }

    // Overdue invoices: amount still unpaid, oldest first.
    $td_overdue = ['n' => 0, 'v' => 0.0, 'rows' => []];
    $q = mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_due, clients.client_name AS client_name,
            invoice_amount - (SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_invoice_id = invoice_id AND payment_archived_at IS NULL) AS owed
        FROM invoices LEFT JOIN clients ON clients.client_id = invoice_client_id
        WHERE invoice_status IN ('Sent', 'Viewed', 'Partial') AND invoice_archived_at IS NULL AND invoice_due < CURRENT_DATE $td_scope
        HAVING owed > 0 ORDER BY invoice_due ASC");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $td_overdue['n']++;
        $td_overdue['v'] += (float) $r['owed'];
        if (count($td_overdue['rows']) < 3) {
            $td_overdue['rows'][] = ['label' => (string) $r['client_name'], 'meta' => $r['invoice_prefix'] . $r['invoice_number'] . ', ' . $td_days($r['invoice_due']) . ' days late', 'amount' => (float) $r['owed'], 'href' => 'invoice.php?invoice_id=' . (int) $r['invoice_id']];
        }
    }

    // Quotes waiting on a yes.
    $td_quotes = ['n' => 0, 'v' => 0.0, 'rows' => []];
    $q = mysqli_query($mysqli, "SELECT quote_id, quote_prefix, quote_number, quote_amount, quote_expire, clients.client_name AS client_name
        FROM quotes LEFT JOIN clients ON clients.client_id = quote_client_id
        WHERE quote_status IN ('Sent', 'Viewed') AND quote_archived_at IS NULL AND (quote_expire IS NULL OR quote_expire >= CURRENT_DATE) $td_scope
        ORDER BY quote_amount DESC");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $td_quotes['n']++;
        $td_quotes['v'] += (float) $r['quote_amount'];
        if (count($td_quotes['rows']) < 3) {
            $left = !empty($r['quote_expire']) ? max(0, (int) ceil((strtotime($r['quote_expire']) - time()) / 86400)) : null;
            $td_quotes['rows'][] = ['label' => (string) $r['client_name'], 'meta' => $r['quote_prefix'] . $r['quote_number'] . ($left === null ? '' : ', expires in ' . $left . ' d'), 'amount' => (float) $r['quote_amount'], 'href' => 'quote.php?quote_id=' . (int) $r['quote_id']];
        }
    }

    $td_columns = [
        ['key' => 'bill', 'title' => 'Ready to bill', 'basis' => 'estimate from logged time x client rate', 'unit' => ['ticket', 'tickets'], 'data' => $td_unbilled, 'cta' => 'Review and invoice', 'href' => 'reports/tickets_unbilled.php', 'empty' => 'Every closed billable ticket is invoiced.'],
        ['key' => 'late', 'title' => 'Overdue', 'basis' => 'unpaid and past due', 'unit' => ['invoice', 'invoices'], 'data' => $td_overdue, 'cta' => 'Collect', 'href' => 'invoices.php?status=Sent', 'empty' => 'No invoice is past due.'],
        ['key' => 'quote', 'title' => 'Waiting on a yes', 'basis' => 'sent and not expired', 'unit' => ['quote', 'quotes'], 'data' => $td_quotes, 'cta' => 'Follow up', 'href' => 'quotes.php?status=Sent', 'empty' => 'No quote is waiting for an answer.'],
    ];
    $td_total = array_sum(array_map(static fn($c) => $c['data']['v'], $td_columns));
    $td_heading = $td_total > 0 ? $td_fmt($td_total) : '';
    $td_title = 'Waiting on you';
} else {
    // Work view for roles without the financial section.
    $td_one = static fn(string $sql): int => (int) (mysqli_fetch_row(mysqli_query($mysqli, $sql))[0] ?? 0);
    $td_base = "FROM tickets LEFT JOIN clients ON clients.client_id = ticket_client_id WHERE ticket_closed_at IS NULL";
    $mine = $td_one("SELECT COUNT(*) $td_base AND ticket_resolved_at IS NULL AND ticket_assigned_to = " . (int) $session_user_id . " $td_scope");
    $late = $td_one("SELECT COUNT(*) $td_base AND ticket_resolved_at IS NULL AND ticket_due_at IS NOT NULL AND ticket_due_at < NOW() $td_scope");
    $unassigned = $td_one("SELECT COUNT(*) $td_base AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0) $td_scope");
    $td_columns = [
        ['key' => 'bill', 'title' => 'Waiting on you', 'basis' => 'open tickets assigned to you', 'data' => ['n' => $mine, 'v' => 0, 'rows' => []], 'cta' => 'Open my tickets', 'href' => 'tickets.php?assigned%5B%5D=me&status=Open', 'empty' => 'Nothing is assigned to you.', 'count_only' => true],
        ['key' => 'late', 'title' => 'Past due', 'basis' => 'open tickets past their due time', 'data' => ['n' => $late, 'v' => 0, 'rows' => []], 'cta' => 'See them', 'href' => 'tickets.php?status=Open&overdue=1', 'empty' => 'Nothing is past due.', 'count_only' => true],
        ['key' => 'quote', 'title' => 'Unassigned', 'basis' => 'open tickets nobody owns yet', 'data' => ['n' => $unassigned, 'v' => 0, 'rows' => []], 'cta' => 'Pick one up', 'href' => 'tickets.php?assigned=0', 'empty' => 'Every ticket has an owner.', 'count_only' => true],
    ];
    $td_total = 0;
    $td_heading = '';
    $td_title = 'Your day';
}
$td_nothing = array_sum(array_map(static fn($c) => $c['data']['n'], $td_columns)) === 0;
?>
<link rel="stylesheet" href="css/dashboard_today.css?v=<?= (int) @filemtime(__DIR__ . '/../css/dashboard_today.css') ?>">
<section class="td-board mb-4" aria-labelledby="td-title">
    <header class="td-head">
        <div>
            <h2 class="td-title" id="td-title"><?= $td_h($td_title) ?></h2>
            <p class="td-sub"><?= $td_nothing ? 'Nothing is waiting. You are caught up.' : ($td_money ? 'Ready to bill is an estimate. Overdue is what is still unpaid.' : 'Counts refresh each time you open the dashboard.') ?></p>
        </div>
        <?php if ($td_heading !== '') { ?>
        <div class="td-total" title="Ready to bill (estimate) + overdue + quotes waiting"><span class="td-total-num"><?= $td_h($td_heading) ?></span><span class="td-total-label">in motion</span></div>
        <?php } ?>
    </header>

    <?php if ($td_money && $td_total > 0) { ?>
    <div class="td-bar" role="img" aria-label="Share of money in motion: <?= $td_h(implode(', ', array_map(static fn($c) => $c['title'] . ' ' . round(($c['data']['v'] / $td_total) * 100) . ' percent', array_filter($td_columns, static fn($c) => $c['data']['v'] > 0)))) ?>">
        <?php foreach ($td_columns as $c) { if ($c['data']['v'] > 0) { ?>
        <span class="td-seg td-seg-<?= $c['key'] ?>" style="flex-grow:<?= max(1, (int) round($c['data']['v'] * 100)) ?>"></span>
        <?php } } ?>
    </div>
    <?php } ?>

    <div class="td-cols">
        <?php foreach ($td_columns as $c) { $d = $c['data']; $count_only = !empty($c['count_only']); ?>
        <article class="td-col td-col-<?= $c['key'] ?>">
            <h3 class="td-col-title"><span class="td-dot td-seg-<?= $c['key'] ?>" aria-hidden="true"></span><?= $td_h($c['title']) ?></h3>
            <?php if ($d['n'] > 0) { ?>
                <p class="td-figure"><?= $count_only ? (int) $d['n'] : $td_h($td_fmt($d['v'])) ?></p>
                <p class="td-basis"><?= $count_only ? $td_h($c['basis']) : (int) $d['n'] . ' ' . $td_h($c['unit'][(int) $d['n'] === 1 ? 0 : 1]) . ' · ' . $td_h($c['basis']) ?></p>
                <?php if (!empty($d['rows'])) { ?>
                <ul class="td-rows">
                    <?php foreach ($d['rows'] as $r) { ?>
                    <li><a href="<?= $td_h($r['href']) ?>"><span class="td-row-main"><span class="td-row-label"><?= $td_h($r['label']) ?></span><span class="td-row-meta"><?= $td_h($r['meta']) ?></span></span><span class="td-row-amt"><?= $td_h($td_fmt($r['amount'])) ?></span></a></li>
                    <?php } ?>
                </ul>
                <?php } ?>
                <a class="btn btn-primary td-cta" href="<?= $td_h($c['href']) ?>"><?= $td_h($c['cta']) ?><i class="fas fa-arrow-right ms-2" aria-hidden="true"></i></a>
            <?php } else { ?>
                <p class="td-clear"><i class="fas fa-check me-2" aria-hidden="true"></i><?= $td_h($c['empty']) ?></p>
            <?php } ?>
        </article>
        <?php } ?>
    </div>
</section>
