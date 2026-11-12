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

/*
 * Roadmap #25 part 2: Pax8 -> client-invoice billing-back.
 *
 * The functions above are pull-only cost/COGS reconciliation. These add the missing other
 * direction: when a client's Pax8 subscription/license count changes, update or create the
 * matching recurring-invoice line item(s) for that client's M365 licensing.
 *
 * Gated at TWO levels so turning Pax8 on (or mapping a new client) never silently starts
 * rewriting a live client invoice - same "opt-in, never a surprise" shape as roadmap #24's
 * contract-pause gating:
 *   1. pax8_integrations.billing_sync_enabled - a single global switch, default off.
 *   2. pax8_client_map.billing_sync_enabled - per mapped client, default off even once the
 *      global switch is on. Both must be 1 or a client's sync is skipped entirely.
 *
 * Pricing: pax8_billing_rules maps a Pax8 product_name (e.g. "Microsoft 365 Business Premium")
 * to either a flat sell_price per seat or a markup_percent over Pax8's unit_cost. A subscription
 * whose product has no enabled rule is never billed-back - it is counted as skipped, not guessed
 * at with some default price.
 *
 * Every line item this writes carries item_pax8_subscription_id, tying it back to the Pax8
 * subscription that produced it. That is how re-syncing tells its own auto-managed lines apart
 * from anything an admin added to the same recurring invoice by hand, and how a cancelled Pax8
 * license gets its line item archived (item_archived_at) rather than silently left stale.
 */

if (!function_exists('pax8BillingRules')) {
    /** All configured billing rules, keyed by Pax8 product_name. */
    function pax8BillingRules(mysqli $mysqli): array {
        $out = [];
        $res = mysqli_query($mysqli, "SELECT * FROM pax8_billing_rules");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $out[$row['product_name']] = $row;
        }
        return $out;
    }
}

if (!function_exists('pax8ComputeSellPrice')) {
    /** The per-unit price to bill the client for one subscription, given its rule + Pax8 unit_cost. */
    function pax8ComputeSellPrice(array $rule, float $unit_cost): float {
        if (($rule['pricing_mode'] ?? 'markup') === 'fixed') {
            return round((float) $rule['sell_price'], 2);
        }
        $markup = (float) ($rule['markup_percent'] ?? 0);
        return round($unit_cost * (1 + ($markup / 100)), 2);
    }
}

if (!function_exists('pax8SaveBillingRule')) {
    /** Upsert a billing rule by product_name (admin/settings_pax8.php Billing Rules tab). */
    function pax8SaveBillingRule(mysqli $mysqli, string $product_name, string $pricing_mode, float $sell_price, float $markup_percent, bool $enabled): void {
        $name_esc = mysqli_real_escape_string($mysqli, $product_name);
        $mode = $pricing_mode === 'fixed' ? 'fixed' : 'markup';
        $enabled_sql = $enabled ? 1 : 0;
        mysqli_query($mysqli, "INSERT INTO pax8_billing_rules (product_name, pricing_mode, sell_price, markup_percent, enabled)
            VALUES ('$name_esc', '$mode', " . floatval($sell_price) . ", " . floatval($markup_percent) . ", $enabled_sql)
            ON DUPLICATE KEY UPDATE pricing_mode = '$mode', sell_price = " . floatval($sell_price) . ", markup_percent = " . floatval($markup_percent) . ", enabled = $enabled_sql");
    }
}

if (!function_exists('pax8CreateBillingRecurringInvoice')) {
    /**
     * Create the dedicated recurring invoice a mapped client's Pax8 license line items get
     * written to, the first time billing sync runs for that client. Mirrors the "new recurring
     * invoice" handler in agent/post/recurring_invoice.php (same atomic next-number counter,
     * same prefix/currency globals) so this looks exactly like one an admin created by hand.
     */
    function pax8CreateBillingRecurringInvoice(mysqli $mysqli, int $client_id): int {
        global $config_recurring_invoice_prefix, $session_company_currency;

        mysqli_query($mysqli, "
            UPDATE settings
            SET
                config_recurring_invoice_next_number = LAST_INSERT_ID(config_recurring_invoice_next_number),
                config_recurring_invoice_next_number = config_recurring_invoice_next_number + 1
            WHERE company_id = 1
        ");
        $recurring_invoice_number = mysqli_insert_id($mysqli);

        $prefix_sql   = mysqli_real_escape_string($mysqli, (string) ($config_recurring_invoice_prefix ?? ''));
        $currency_sql = mysqli_real_escape_string($mysqli, (string) ($session_company_currency ?? 'USD'));

        mysqli_query($mysqli, "INSERT INTO recurring_invoices SET
            recurring_invoice_prefix = '$prefix_sql', recurring_invoice_number = $recurring_invoice_number,
            recurring_invoice_scope = 'Pax8 M365 Licensing (auto-managed)', recurring_invoice_frequency = 'month',
            recurring_invoice_next_date = CURDATE(), recurring_invoice_status = 1, recurring_invoice_amount = 0.00,
            recurring_invoice_currency_code = '$currency_sql', recurring_invoice_category_id = 0, recurring_invoice_client_id = " . intval($client_id));

        $recurring_invoice_id = (int) mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Active',
            history_description = 'Recurring Invoice created automatically by Pax8 billing sync',
            history_recurring_invoice_id = $recurring_invoice_id");
        logAction("Pax8", "Create", "Pax8 billing sync created recurring invoice for automated M365 license billing", $client_id, $recurring_invoice_id);

        return $recurring_invoice_id;
    }
}

if (!function_exists('pax8RecalcRecurringInvoiceAmount')) {
    /** Recompute recurring_invoice_amount from its (non-archived) items, same formula used throughout agent/post/recurring_invoice.php. */
    function pax8RecalcRecurringInvoiceAmount(mysqli $mysqli, int $recurring_invoice_id): void {
        $discount = (float) (mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT recurring_invoice_discount_amount FROM recurring_invoices WHERE recurring_invoice_id = $recurring_invoice_id"))['recurring_invoice_discount_amount'] ?? 0);
        $total = (float) (mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(SUM(item_total), 0) AS t FROM recurring_invoice_items WHERE item_recurring_invoice_id = $recurring_invoice_id AND item_archived_at IS NULL"))['t'] ?? 0);
        $amount = round($total - $discount, 2);
        mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_amount = $amount WHERE recurring_invoice_id = $recurring_invoice_id");
    }
}

if (!function_exists('pax8BillingSyncForClient')) {
    /**
     * Sync one mapped client's active Pax8 subscriptions into their dedicated recurring
     * invoice's line items. Returns ['skipped'=>string] if gated off, else
     * ['added'=>int,'updated'=>int,'archived'=>int,'skipped_no_rule'=>int,'recurring_invoice_id'=>int].
     */
    function pax8BillingSyncForClient(mysqli $mysqli, int $client_id): array {
        $integration = getPax8Integration($mysqli);
        if (!$integration || intval($integration['billing_sync_enabled']) !== 1) {
            return ['skipped' => 'global billing sync is disabled'];
        }

        // MSP-INJ-22 (b): deterministic - the first company row WITH billing on (oldest first), never an arbitrary one when several companies map to one client
        $map = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM pax8_client_map WHERE client_id = " . intval($client_id) . " AND billing_sync_enabled = 1 ORDER BY map_id ASC LIMIT 1"));
        if (!$map || intval($map['billing_sync_enabled']) !== 1) {
            return ['skipped' => 'billing sync is not enabled for this client'];
        }

        $rules = pax8BillingRules($mysqli);

        $recurring_invoice_id = intval($map['recurring_invoice_id'] ?? 0);
        // The stored invoice must still belong to the client being synced: a mapping that was re-pointed at a different
        // client must never keep writing line items onto the previous client's invoice.
        $client_id_int = intval($client_id);
        if ($recurring_invoice_id <= 0 || !mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_id = $recurring_invoice_id AND recurring_invoice_client_id = $client_id_int AND recurring_invoice_archived_at IS NULL"))) {
            $recurring_invoice_id = pax8CreateBillingRecurringInvoice($mysqli, $client_id);
            mysqli_query($mysqli, "UPDATE pax8_client_map SET recurring_invoice_id = $recurring_invoice_id WHERE map_id = " . intval($map['map_id']));
        }

        $added = 0; $updated = 0; $archived = 0; $skipped_no_rule = 0;
        $seen_subscription_ids = [];

        // only subscriptions of companies that are mapped to THIS client with billing on (a company that was un-mapped or has billing off is never billed)
        $subs_res = mysqli_query($mysqli, "SELECT s.* FROM pax8_subscriptions s JOIN pax8_client_map m ON m.pax8_company_id = s.pax8_company_id WHERE m.client_id = " . intval($client_id) . " AND m.billing_sync_enabled = 1 AND s.client_id = " . intval($client_id) . " AND s.status = 'ACTIVE'");
        while ($subs_res && ($sub = mysqli_fetch_assoc($subs_res))) {
            $product_name = (string) $sub['product_name'];
            $rule = $rules[$product_name] ?? null;
            if (!$rule || intval($rule['enabled']) !== 1) {
                $skipped_no_rule++;
                continue;
            }

            $sub_id_esc = mysqli_real_escape_string($mysqli, $sub['pax8_subscription_id']);
            $seen_subscription_ids[] = $sub['pax8_subscription_id'];

            $quantity = (float) $sub['quantity'];
            $sell_price = pax8ComputeSellPrice($rule, (float) $sub['unit_cost']);
            $subtotal = round($sell_price * $quantity, 2);
            $name_esc = mysqli_real_escape_string($mysqli, $product_name);

            $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM recurring_invoice_items WHERE item_recurring_invoice_id = $recurring_invoice_id AND item_pax8_subscription_id = '$sub_id_esc' LIMIT 1"));

            if (!$existing) {
                mysqli_query($mysqli, "INSERT INTO recurring_invoice_items SET
                    item_name = '$name_esc', item_description = 'Pax8-managed: synced from Pax8 subscription',
                    item_quantity = $quantity, item_price = $sell_price, item_subtotal = $subtotal, item_tax = 0,
                    item_total = $subtotal, item_order = 0, item_tax_id = 0,
                    item_recurring_invoice_id = $recurring_invoice_id, item_pax8_subscription_id = '$sub_id_esc'");
                $added++;
                logAction("Pax8", "Create", "Pax8 billing sync added line item \"$product_name\" x$quantity @ " . number_format($sell_price, 2) . " to recurring invoice", $client_id, $recurring_invoice_id);
                continue;
            }

            $was_archived = !empty($existing['item_archived_at']);
            $qty_changed  = abs((float) $existing['item_quantity'] - $quantity) > 0.001;
            $price_changed = abs((float) $existing['item_price'] - $sell_price) > 0.001;

            if ($was_archived || $qty_changed || $price_changed) {
                mysqli_query($mysqli, "UPDATE recurring_invoice_items SET
                    item_name = '$name_esc', item_quantity = $quantity, item_price = $sell_price,
                    item_subtotal = $subtotal, item_total = $subtotal, item_archived_at = NULL
                    WHERE item_id = " . intval($existing['item_id']));
                $updated++;
                $old_qty = (float) $existing['item_quantity'];
                $old_price = (float) $existing['item_price'];
                logAction("Pax8", "Edit", "Pax8 billing sync updated line item \"$product_name\" on recurring invoice: qty $old_qty -> $quantity, price " . number_format($old_price, 2) . " -> " . number_format($sell_price, 2), $client_id, $recurring_invoice_id);
            }
        }

        // Archive any Pax8-managed line item on this recurring invoice whose subscription is no
        // longer active/present (cancelled in Pax8) - never delete, so the invoice history stays intact.
        $seen_sql = empty($seen_subscription_ids) ? "''" : "'" . implode("','", array_map(fn ($id) => mysqli_real_escape_string($mysqli, $id), $seen_subscription_ids)) . "'";
        $stale_res = mysqli_query($mysqli, "SELECT item_id, item_name FROM recurring_invoice_items
            WHERE item_recurring_invoice_id = $recurring_invoice_id AND item_pax8_subscription_id IS NOT NULL
              AND item_pax8_subscription_id NOT IN ($seen_sql) AND item_archived_at IS NULL");
        while ($stale_res && ($row = mysqli_fetch_assoc($stale_res))) {
            mysqli_query($mysqli, "UPDATE recurring_invoice_items SET item_archived_at = NOW() WHERE item_id = " . intval($row['item_id']));
            $archived++;
            logAction("Pax8", "Edit", "Pax8 billing sync archived line item \"{$row['item_name']}\" - no longer an active Pax8 subscription", $client_id, $recurring_invoice_id);
        }

        if ($added > 0 || $updated > 0 || $archived > 0) {
            pax8RecalcRecurringInvoiceAmount($mysqli, $recurring_invoice_id);
        }

        return ['added' => $added, 'updated' => $updated, 'archived' => $archived, 'skipped_no_rule' => $skipped_no_rule, 'recurring_invoice_id' => $recurring_invoice_id];
    }
}

if (!function_exists('pax8BillingSyncAll')) {
    /**
     * Runs pax8BillingSyncForClient() for every mapped+enabled client. Returns an aggregate summary.
     * Serialised with a MySQL named lock (works without Redis, freed if the connection drops) so the cron run and the
     * manual "Sync Billing Now" button can never interleave their check-then-insert on line items. If another run
     * holds the lock the totals come back with 'locked' => true and nothing is touched.
     */
    function pax8BillingSyncAll(mysqli $mysqli): array {
        $totals = ['clients' => 0, 'added' => 0, 'updated' => 0, 'archived' => 0, 'skipped_no_rule' => 0];
        $integration = getPax8Integration($mysqli);
        if (!$integration || intval($integration['billing_sync_enabled']) !== 1) {
            return $totals;
        }

        $got = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('rivet_pax8_billing_sync', 0)"));
        if (!$got || intval($got[0]) !== 1) {
            $totals['locked'] = true;
            return $totals;
        }
        try {
            $res = mysqli_query($mysqli, "SELECT client_id FROM pax8_client_map WHERE client_id IS NOT NULL AND billing_sync_enabled = 1");
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $result = pax8BillingSyncForClient($mysqli, intval($row['client_id']));
                if (isset($result['skipped'])) {
                    continue;
                }
                $totals['clients']++;
                $totals['added'] += $result['added'];
                $totals['updated'] += $result['updated'];
                $totals['archived'] += $result['archived'];
                $totals['skipped_no_rule'] += $result['skipped_no_rule'];
            }
        } finally {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('rivet_pax8_billing_sync')");
        }
        return $totals;
    }
}
