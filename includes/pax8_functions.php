<?php
/*
 * ITFlow - Pax8 cost sync + billing/COGS reconciliation helpers (roadmap #25).
 *
 * Pull-only: pulls Pax8 companies and their subscription costs, lets an admin map a Pax8
 * company to a local client (pax8_client_map, same shape as the accounting client-mapping
 * tables), and reconciles the pulled monthly subscription cost against what that client is
 * actually billed in RivetMSP (recurring invoices) - margin = billed - cost.
 */

if (!function_exists('getPax8Integration')) {
    /** Fetch the (single) Pax8 integration row, or null if none configured. */
    function getPax8Integration(mysqli $mysqli): ?array {
        $res = mysqli_query($mysqli, "SELECT * FROM pax8_integrations ORDER BY id ASC LIMIT 1");
        if (!$res) {
            return null;
        }
        $row = mysqli_fetch_assoc($res);
        return $row ?: null;
    }
}

if (!function_exists('makePax8Client')) {
    /**
     * Test-only endpoint overrides (RIVETMSP_TEST_PAX8_TOKEN_URL / _API_BASE) point Pax8Client
     * at a loopback fake server instead of Pax8's real hosts - see tests/pax8_cost_sync.php.
     * Unset in every real environment, where this is identical to `new Pax8Client(...)`.
     */
    function makePax8Client(mysqli $mysqli, int $integration_id): Pax8Client {
        $overrides = [];
        if (getenv('RIVETMSP_TEST_PAX8_TOKEN_URL')) { $overrides['token_url'] = getenv('RIVETMSP_TEST_PAX8_TOKEN_URL'); }
        if (getenv('RIVETMSP_TEST_PAX8_API_BASE'))  { $overrides['api_base']  = getenv('RIVETMSP_TEST_PAX8_API_BASE'); }
        return new Pax8Client($mysqli, $integration_id, $overrides);
    }
}

if (!function_exists('pax8SyncCompanies')) {
    /**
     * Pull every Pax8 company and upsert it into pax8_client_map (preserving any existing
     * client_id link - this never touches a mapping a human already made). Returns the count
     * of companies seen.
     */
    function pax8SyncCompanies(mysqli $mysqli, Pax8Client $pax8): int {
        $companies = $pax8->getCompanies();
        foreach ($companies as $c) {
            $id_esc   = mysqli_real_escape_string($mysqli, $c['id']);
            $name_esc = mysqli_real_escape_string($mysqli, $c['name']);
            mysqli_query($mysqli, "INSERT INTO pax8_client_map (pax8_company_id, pax8_company_name)
                VALUES ('$id_esc', '$name_esc')
                ON DUPLICATE KEY UPDATE pax8_company_name = VALUES(pax8_company_name)");
        }
        return count($companies);
    }
}

if (!function_exists('pax8SyncSubscriptionsForCompany')) {
    /**
     * Pull every subscription for one Pax8 company and replace its pax8_subscriptions rows
     * (delete-then-reinsert, same convention as ticket_tags-style replace-all syncs elsewhere in
     * this codebase - a cancelled Pax8 subscription must disappear from the next sync, not linger).
     * $client_id is the mapped local client (from pax8_client_map), or 0 if unmapped yet - stored
     * either way so mapping a company AFTER a sync still has cost data to reconcile against
     * immediately without waiting for the next pull.
     */
    function pax8SyncSubscriptionsForCompany(mysqli $mysqli, Pax8Client $pax8, string $companyId, int $client_id): int {
        $subs = $pax8->getSubscriptionsForCompany($companyId);
        $company_id_esc = mysqli_real_escape_string($mysqli, $companyId);
        mysqli_query($mysqli, "DELETE FROM pax8_subscriptions WHERE pax8_company_id = '$company_id_esc'");
        foreach ($subs as $s) {
            $sub_id_esc  = mysqli_real_escape_string($mysqli, $s['id']);
            $name_esc    = mysqli_real_escape_string($mysqli, $s['productName']);
            $term_esc    = mysqli_real_escape_string($mysqli, $s['billingTerm']);
            $status_esc  = mysqli_real_escape_string($mysqli, $s['status']);
            $client_sql  = $client_id > 0 ? $client_id : 'NULL';
            mysqli_query($mysqli, "INSERT INTO pax8_subscriptions
                (pax8_subscription_id, pax8_company_id, client_id, product_name, quantity, unit_cost, total_cost, billing_term, status, synced_at)
                VALUES ('$sub_id_esc', '$company_id_esc', $client_sql, '$name_esc', " . intval($s['quantity']) . ", " . floatval($s['unitCost']) . ", " . floatval($s['totalCost']) . ", '$term_esc', '$status_esc', NOW())
                ON DUPLICATE KEY UPDATE
                    client_id = $client_sql, product_name = '$name_esc', quantity = " . intval($s['quantity']) . ",
                    unit_cost = " . floatval($s['unitCost']) . ", total_cost = " . floatval($s['totalCost']) . ",
                    billing_term = '$term_esc', status = '$status_esc', synced_at = NOW()");
        }
        return count($subs);
    }
}

if (!function_exists('pax8SyncAll')) {
    /**
     * Full sync: companies, then subscriptions for every MAPPED company (unmapped companies'
     * costs can't be reconciled against anything yet, so there is no point pulling them until
     * an admin maps them - pax8SyncCompanies() still lists them for mapping either way).
     * Returns ['companies'=>int,'subscriptions'=>int,'mapped_companies'=>int].
     */
    function pax8SyncAll(mysqli $mysqli, Pax8Client $pax8, int $integration_id): array {
        $companies = pax8SyncCompanies($mysqli, $pax8);
        $mapped = mysqli_query($mysqli, "SELECT pax8_company_id, client_id FROM pax8_client_map WHERE client_id IS NOT NULL");
        $sub_total = 0;
        $mapped_count = 0;
        while ($mapped && ($row = mysqli_fetch_assoc($mapped))) {
            $mapped_count++;
            $sub_total += pax8SyncSubscriptionsForCompany($mysqli, $pax8, $row['pax8_company_id'], intval($row['client_id']));
        }
        mysqli_query($mysqli, "UPDATE pax8_integrations SET last_synced_at = NOW() WHERE id = " . intval($integration_id));
        return ['companies' => $companies, 'subscriptions' => $sub_total, 'mapped_companies' => $mapped_count];
    }
}

if (!function_exists('pax8ReconciliationRows')) {
    /**
     * Per mapped client: Pax8 monthly subscription cost vs RivetMSP's current monthly recurring
     * revenue billed to that client (sum of active, non-archived recurring_invoices for that
     * client - the "what they're actually billed" side of the margin). Scope note (v1): this
     * compares CLIENT-level totals, not a line-item-to-SKU match - a genuine simplification
     * flagged on purpose, not a silent gap: a per-product/SKU breakdown would need its own
     * mapping table (mirroring accounting_item_mapping's shape) and is a reasonable v2.
     *
     * Returns one row per mapped client:
     *   ['client_id','client_name','pax8_cost','billed','margin','margin_pct']
     * margin_pct is null when billed is 0 (can't divide).
     */
    function pax8ReconciliationRows(mysqli $mysqli): array {
        $out = [];
        $res = mysqli_query($mysqli, "
            SELECT c.client_id, c.client_name,
                   COALESCE((SELECT SUM(ps.total_cost) FROM pax8_subscriptions ps WHERE ps.client_id = c.client_id AND ps.status = 'ACTIVE'), 0) AS pax8_cost,
                   COALESCE((SELECT SUM(ri.recurring_invoice_amount) FROM recurring_invoices ri WHERE ri.recurring_invoice_client_id = c.client_id AND ri.recurring_invoice_archived_at IS NULL), 0) AS billed
            FROM clients c
            WHERE c.client_id IN (SELECT client_id FROM pax8_client_map WHERE client_id IS NOT NULL)
              AND c.client_archived_at IS NULL
            ORDER BY c.client_name ASC
        ");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $cost   = round((float) $row['pax8_cost'], 2);
            $billed = round((float) $row['billed'], 2);
            $margin = round($billed - $cost, 2);
            $out[] = [
                'client_id'  => intval($row['client_id']),
                'client_name' => (string) $row['client_name'],
                'pax8_cost'  => $cost,
                'billed'     => $billed,
                'margin'     => $margin,
                'margin_pct' => $billed > 0 ? round(($margin / $billed) * 100, 1) : null,
            ];
        }
        return $out;
    }
}
