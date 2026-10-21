<?php

/*
 * ITFlow - Mobile push notification categories
 * Maps the freeform notification "type" strings used by appNotify()/notifyUser()
 * into a small set of user-selectable categories for push targeting.
 */

function push_notification_categories(): array {
    return [
        'tickets' => [
            'label' => 'Tickets',
            'icon'  => 'fas fa-ticket-alt',
            'types' => ['Ticket', 'Recurring Ticket', 'Pending Tickets', 'Feedback', 'Automation'],
        ],
        'invoices' => [
            'label' => 'Invoices & Expenses',
            'icon'  => 'fas fa-file-invoice-dollar',
            'types' => ['Invoice', 'Invoice Paid', 'Invoice Overdue', 'Invoice Late Charge', 'Invoice Viewed', 'Recurring Sent', 'Expense', 'Expense Created'],
        ],
        'quotes' => [
            'label' => 'Quotes',
            'icon'  => 'fas fa-file-signature',
            'types' => ['Quote Accepted', 'Quote Declined', 'Quote File', 'Quote Viewed'],
        ],
        'expirations' => [
            'label' => 'Expirations',
            'icon'  => 'fas fa-hourglass-end',
            'types' => ['Domain Expiring', 'Certificate Expiring', 'Asset Warranty Expiring'],
        ],
        'backups' => [
            'label' => 'Backups',
            'icon'  => 'fas fa-database',
            'types' => ['Backup', 'Comet Backup'],
        ],
        'system' => [
            'label' => 'System & Admin',
            'icon'  => 'fas fa-cogs',
            'types' => ['Cron', 'Cron-Mail-Queue', 'Mail', 'Master Key', 'Settings', 'Update', 'Share Viewed', 'Integration'],
        ],
    ];
}

function push_category_for_type(string $type): ?string {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (push_notification_categories() as $key => $cat) {
            foreach ($cat['types'] as $t) {
                $lookup[$t] = $key;
            }
        }
    }
    return $lookup[$type] ?? null;
}

// Categories globally allowed to push, per admin settings. Null/empty config = all allowed.
function push_globally_enabled_categories(): array {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_push_enabled_types FROM settings WHERE company_id = 1"));
    $raw = $row['config_push_enabled_types'] ?? null;
    if (!$raw) return array_keys(push_notification_categories());
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array_keys(push_notification_categories());
}

// Categories this user wants pushed, intersected with what's globally allowed. Null user config = all globally-allowed categories.
function push_enabled_categories_for_user(int $user_id): array {
    global $mysqli;
    $global = push_globally_enabled_categories();
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_config_push_types FROM user_settings WHERE user_id = " . intval($user_id)));
    $raw = $row['user_config_push_types'] ?? null;
    if (!$raw) return $global;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $global;
    return array_values(array_intersect($decoded, $global));
}

// Should a notification of $type be pushed to $user_id's mobile devices?
function push_allowed_for_user(int $user_id, string $type): bool {
    $category = push_category_for_type($type);
    if ($category === null) return true; // unmapped types default to allowed
    return in_array($category, push_enabled_categories_for_user($user_id), true);
}

/*
 * In-app notifications (the bell): which categories a user wants at all. A muted category is never written to the
 * notifications table for that user (appNotify(), notifyUser() and the few direct INSERTs all check it here), so it
 * never reaches the bell, the live stream or a phone either.
 *
 * Finer than the push categories above on purpose: "Cron successfully executed" (every cron run) and "Update" must be
 * switchable on their own without also silencing mail failures or security notices that share push's "System" group.
 *
 * Stored per user in user_settings.user_config_inapp_muted_types (DB 2.6.105) as a JSON list of MUTED category keys
 * (NULL = nothing muted). A mute list, not an allow list like user_config_push_types: a category added later starts
 * switched on for everyone instead of silently off for every user who ever saved this card.
 *
 * 'locked' categories cannot be muted (security notices, and RMM alert escalations an administrator routed to this
 * person on purpose): the switch is shown on and disabled, the save handler never stores them, and the check ignores
 * them even if a stored list names them. A type that is in no category is always delivered (tests/notification_preferences.php
 * fails when a notification type used in the code is missing from this list).
 */
function inapp_notification_categories(): array {
    return [
        'tickets' => [
            'label' => 'Tickets',
            'icon'  => 'fas fa-ticket-alt',
            'hint'  => 'New tickets, replies, assignments, task approvals, satisfaction feedback, automation rules.',
            'types' => ['Ticket', 'Recurring Ticket', 'Pending Tickets', 'Feedback', 'Automation'],
        ],
        'crm' => [
            'label' => 'CRM follow-ups',
            'icon'  => 'fas fa-handshake',
            'hint'  => 'Follow-up reminders on CRM activities.',
            'types' => ['CRM Follow-up'],
        ],
        'invoices' => [
            'label' => 'Invoices & expenses',
            'icon'  => 'fas fa-file-invoice-dollar',
            'hint'  => 'Payments, overdue invoices, late fees, recurring invoices and expenses.',
            'types' => ['Invoice', 'Invoice Paid', 'Invoice Overdue', 'Invoice Late Charge', 'Invoice Viewed', 'Recurring Sent', 'Expense', 'Expense Created'],
        ],
        'quotes' => [
            'label' => 'Quotes',
            'icon'  => 'fas fa-file-signature',
            'hint'  => 'Quotes viewed, accepted or declined, and files clients upload to them.',
            'types' => ['Quote Accepted', 'Quote Declined', 'Quote File', 'Quote Viewed'],
        ],
        'expirations' => [
            'label' => 'Expirations & reviews',
            'icon'  => 'fas fa-hourglass-end',
            'hint'  => 'Domains, certificates and asset warranties about to expire; documents due for review.',
            'types' => ['Domain Expiring', 'Certificate Expiring', 'Asset Warranty Expiring', 'Document Review Due'],
        ],
        'backups' => [
            'label' => 'Backups',
            'icon'  => 'fas fa-database',
            'hint'  => 'Automatic backup saved or refused, Comet backup failures.',
            'types' => ['Backup', 'Comet Backup'],
        ],
        'recovery' => [
            'label' => 'Backup & sync health',
            'icon'  => 'fas fa-heartbeat',
            'hint'  => 'Stale backups, failing integration syncs, restore drills that stopped running.',
            'types' => ['Recovery'],
        ],
        'mail' => [
            'label' => 'Mail problems',
            'icon'  => 'fas fa-envelope',
            'hint'  => 'Outgoing mail that could not be sent, mailbox health.',
            'types' => ['Mail'],
        ],
        'rmm' => [
            'label' => 'RMM housekeeping',
            'icon'  => 'fas fa-desktop',
            'hint'  => 'Assets retired because their RMM agent went silent.',
            'types' => ['Stale assets retired'],
        ],
        'integrations' => [
            'label' => 'Integrations',
            'icon'  => 'fas fa-plug',
            'hint'  => 'A connected account (such as Outlook Calendar) needs reconnecting.',
            'types' => ['Integration'],
        ],
        'sharing' => [
            'label' => 'Shared links viewed',
            'icon'  => 'fas fa-share-alt',
            'hint'  => 'Someone opened an item that was shared with a link.',
            'types' => ['Share Viewed'],
        ],
        'updates' => [
            'label' => 'Update available',
            'icon'  => 'fas fa-cloud-download-alt',
            'hint'  => 'A new RivetMSP release is available (checked once a day).',
            'types' => ['Update'],
        ],
        'cron' => [
            'label' => 'Scheduled tasks ran',
            'icon'  => 'fas fa-clock',
            'hint'  => '"Cron successfully executed", after every run of the scheduled tasks.',
            'types' => ['Cron'],
        ],
        'security' => [
            'label'  => 'Security',
            'icon'   => 'fas fa-shield-alt',
            'hint'   => 'Master key retrieved, credential reveal limit reached.',
            'types'  => ['Master Key', 'Vault'],
            'locked' => true,
        ],
        'rmm_escalation' => [
            'label'  => 'RMM alert escalations',
            'icon'   => 'fas fa-bell',
            'hint'   => 'An RMM alert that an escalation policy routed to you.',
            'types'  => ['RMM Alert Escalation'],
            'locked' => true,
        ],
    ];
}

function inapp_category_for_type(string $type): ?string {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (inapp_notification_categories() as $key => $cat) {
            foreach ($cat['types'] as $t) {
                $lookup[$t] = $key;
            }
        }
    }
    return $lookup[$type] ?? null;
}

// Category keys a user may mute (every category that is not locked).
function inapp_mutable_category_keys(): array {
    return array_keys(array_filter(inapp_notification_categories(), fn($cat) => empty($cat['locked'])));
}

// The mutable category keys a stored user_config_inapp_muted_types value names. NULL, '' or anything malformed = nothing muted.
function inapp_muted_categories_from_json(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_intersect(inapp_mutable_category_keys(), array_filter($decoded, 'is_string')));
}

// Is a notification of $type muted by this list of muted category keys? Unmapped types and locked categories never are.
function inapp_type_muted(string $type, array $muted_categories): bool {
    $category = inapp_category_for_type($type);
    if ($category === null || !in_array($category, inapp_mutable_category_keys(), true)) return false;
    return in_array($category, $muted_categories, true);
}

// Muted category keys for one user. Fails open (nothing muted) if the column is not there yet, i.e. new code running before DB 2.6.105:
// a notification must never be lost because a preference could not be read.
function inapp_muted_categories_for_user(int $user_id): array {
    global $mysqli;
    try {
        $res = mysqli_query($mysqli, "SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = " . intval($user_id));
        $row = $res ? mysqli_fetch_assoc($res) : null;
    } catch (\Throwable $e) {
        return [];
    }
    return inapp_muted_categories_from_json($row['user_config_inapp_muted_types'] ?? null);
}

// user_id => muted category keys, for every user who muted anything (one query for appNotify()'s fan-out). Fails open like the above.
function inapp_muted_categories_by_user(): array {
    global $mysqli;
    $out = [];
    try {
        $res = mysqli_query($mysqli, "SELECT user_id, user_config_inapp_muted_types FROM user_settings WHERE user_config_inapp_muted_types IS NOT NULL AND user_config_inapp_muted_types <> ''");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $muted = inapp_muted_categories_from_json($row['user_config_inapp_muted_types']);
            if ($muted) {
                $out[intval($row['user_id'])] = $muted;
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $out;
}

// Should an in-app notification of $type be created for $user_id at all?
function inapp_notification_allowed_for_user(int $user_id, string $type): bool {
    return !inapp_type_muted($type, inapp_muted_categories_for_user($user_id));
}
