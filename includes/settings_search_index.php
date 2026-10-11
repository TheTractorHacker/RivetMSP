<?php

/*
 * Static index of admin Settings pages/sections, used by the global search
 * (agent/ajax.php's global_search_live + agent/global_search.php) so settings
 * are findable the same way departments/tickets/etc. are - Settings pages
 * aren't database rows, so there's nothing to LIKE-match in SQL; this is a
 * plain PHP array instead, filtered in-process against the search query.
 *
 * Each entry's "visible" key controls whether that destination appears in
 * search. The settings directory also applies feature flags to its links.
 *
 * An entry for a page with in-page sections may carry 'sections' (anchor,
 * label, keywords), and a section may carry 'parts': cards inside it with
 * their own anchor, label and keywords. Their words match the entry too, and
 * the result opens the most specific place the query names:
 *   - the entry's own label or keywords -> the page itself;
 *   - else the first section whose label or keywords hold it -> '#anchor',
 *     titled "Page › Section";
 *   - else the first part whose label or keywords hold it -> that card's
 *     '#anchor', titled "Page › Card".
 * Still one result per page.
 */

function getSettingsSearchIndex(): array {
    global $config_module_enable_accounting, $config_module_enable_ticketing, $config_module_enable_itdoc,
           $config_module_enable_rmm, $config_module_enable_unifi, $config_module_enable_payroll,
           $config_module_enable_intune, $config_client_portal_enable;

    return [
        ['label' => 'All settings',           'keywords' => ['admin settings', 'configure', 'preferences'],                         'url' => '/admin/settings.php',                    'visible' => true],
        ['label' => 'Tags & categories',       'keywords' => ['tags', 'categories', 'custom links', 'ai providers'], 'url' => '/admin/catalog_setup.php',           'visible' => true],
        ['label' => 'Ticketing setup',         'keywords' => ['ticket statuses', 'labor types', 'mailboxes', 'sla', 'sla policies', 'business hours', 'holidays', 'ticket automation'],      'url' => '/admin/ticketing_setup.php',             'visible' => (bool) $config_module_enable_ticketing],
        ['label' => 'Templates',               'keywords' => ['ticket template', 'project template', 'contract template', 'canned response', 'onboarding template', 'client workflow', 'workflow template'], 'url' => '/admin/template_library.php',     'visible' => (bool) $config_module_enable_itdoc],
        ['label' => 'Maintenance',             'keywords' => ['cron', 'scheduled jobs', 'logs', 'backup', 'update', 'redis', 'audit'],                'url' => '/admin/maintenance.php',               'visible' => true],
        ['label' => 'Company Details',        'keywords' => ['company', 'address', 'logo', 'business info'],                          'url' => '/admin/settings_company.php',            'visible' => true],
        ['label' => 'Localization',            'keywords' => ['locale', 'timezone', 'currency', 'language', 'date format'],            'url' => '/admin/settings_localization.php',       'visible' => true],
        ['label' => 'Theme',                   'keywords' => ['theme', 'dark mode', 'color', 'accent', 'favicon'],                     'url' => '/admin/settings_theme.php',              'visible' => true],
        ['label' => 'Knowledge Base Settings', 'keywords' => ['knowledge base', 'kb', 'articles', 'documentation'],                  'url' => '/admin/settings_kb.php',                 'visible' => true],
        ['label' => 'Appearance',              'keywords' => ['appearance', 'logo', 'branding'],                                       'url' => '/admin/settings_appearance.php',         'visible' => true],
        ['label' => 'Security',                'keywords' => ['security', 'vault', 'encryption', 'login key', 'passkey'],              'url' => '/admin/settings_security.php',           'visible' => true],
        ['label' => 'Compliance',                'keywords' => ['compliance', 'retention', 'audit', 'iso 27001', 'soc 2', 'pci', 'hipaa', 'logs', 'keep records'],              'url' => '/admin/settings_compliance.php',           'visible' => true],
        ['label' => 'Compliance status',         'keywords' => ['compliance', 'checklist', 'audit report', 'auditor', 'iso 27001', 'soc 2', 'pci', 'hipaa', 'export'],      'url' => '/admin/compliance_status.php',             'visible' => true],
        ['label' => 'Event rules',               'keywords' => ['automation', 'rules', 'event', 'trigger', 'when', 'webhook'],                                                'url' => '/admin/event_rules.php',                   'visible' => true],
        ['label' => 'Job queue',                 'keywords' => ['jobs', 'queue', 'retry', 'failed', 'webhook delivery', 'background'],                                       'url' => '/admin/job_queue.php',                     'visible' => true],
        ['label' => 'Audit trail',               'keywords' => ['audit', 'trail', 'who did', 'history', 'sign-in', 'login', 'security log', 'export', 'tamper', 'hash chain', 'syslog', 'siem', 'json lines'],            'url' => '/admin/audit_trail.php',                   'visible' => true],
        ['label' => 'Mail',                    'keywords' => ['smtp', 'imap', 'email', 'oauth', 'mail'],                               'url' => '/admin/settings_mail.php',               'visible' => true],
        ['label' => 'Notifications',           'keywords' => ['notification', 'alert'],                                                'url' => '/admin/settings_notification.php',       'visible' => true],
        ['label' => 'Defaults',                'keywords' => ['default', 'default technician'],                                        'url' => '/admin/settings_default.php',            'visible' => true],
        ['label' => 'Invoice Settings',        'keywords' => ['invoice', 'tax id', 'late fee', 'recurring invoice'],                    'url' => '/admin/settings_invoice.php',            'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Quote Settings',          'keywords' => ['quote', 'estimate'],                                                    'url' => '/admin/settings_quote.php',              'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Project Settings',        'keywords' => ['project'],                                                              'url' => '/admin/settings_project.php',            'visible' => true],
        ['label' => 'Ticket Settings',         'keywords' => ['ticket', 'csat', 'customer satisfaction', 'rating', 'survey'],           'url' => '/admin/settings_ticket.php',             'visible' => true],
        ['label' => 'Outlook Calendar Sync',   'keywords' => ['outlook', 'calendar', 'azure', 'sync', 'appointment'],                   'url' => '/admin/settings_calendar_sync.php',      'visible' => true],
        ['label' => 'Telemetry',               'keywords' => ['telemetry', 'analytics', 'usage data'],                                 'url' => '/admin/settings_telemetry.php',          'visible' => true],
        ['label' => 'Modules',                 'keywords' => ['module', 'documentation', 'knowledge base', 'live chat', 'client portal', 'enable'], 'url' => '/admin/settings_module.php', 'visible' => true],
        ['label' => 'Webhooks',                'keywords' => ['webhook', 'api', 'delivery log'],                                       'url' => '/admin/settings_webhooks.php',           'visible' => true],
        ['label' => 'RMM Integration',         'keywords' => ['rmm', 'remote monitoring', 'tactical', 'level.io', 'sophos', 'action1', 'connectwise', 'client mapping', 'needs mapping', 'stale assets', 'retire'], 'url' => '/admin/settings_integrations.php?tab=rmm', 'visible' => true],
        ['label' => 'Backups Integration',     'keywords' => ['backup', 'comet'],                                                      'url' => '/admin/settings_integrations.php?tab=backups', 'visible' => true],
        ['label' => 'Firewalls Integration',   'keywords' => ['firewall', 'sophos central'],                                           'url' => '/admin/settings_integrations.php?tab=firewalls', 'visible' => true],
        ['label' => 'UniFi Integration',       'keywords' => ['unifi', 'wifi', 'network', 'ubiquiti'],                                  'url' => '/admin/settings_integrations.php?tab=unifi', 'visible' => true],
        ['label' => 'Intune Devices Module',   'keywords' => ['intune', 'devices', 'microsoft', 'entra', 'azure ad', 'mdm'],            'url' => '/admin/settings_integrations.php?tab=directorysync', 'visible' => true],
        ['label' => 'Microsoft 365 / Entra ID','keywords' => ['microsoft 365', 'entra', 'azure ad', 'tenant', 'graph api'],             'url' => '/admin/settings_integrations.php?tab=directorysync', 'visible' => true],
        ['label' => 'Custom Fields',           'keywords' => ['custom field'],                                                         'url' => '/admin/settings_custom_fields.php',      'visible' => true],
        ['label' => 'Accounting (QuickBooks)',  'keywords' => ['accounting', 'quickbooks', 'qbo', 'client mapping', 'item mapping', 'sync status'],               'url' => '/admin/settings_accounting.php',        'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Payroll',                  'keywords' => ['payroll', 'pay period', 'deductions', 'employees'],                                                'url' => '/admin/payroll_settings.php',           'visible' => (bool) $config_module_enable_payroll],
        ['label' => 'Taxes',                    'keywords' => ['tax', 'vat', 'sales tax'],                                                                          'url' => '/admin/tax.php',                        'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Payment Methods',          'keywords' => ['payment method', 'check', 'cash', 'ach'],                                                          'url' => '/admin/payment_method.php',             'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Payment Providers',        'keywords' => ['stripe', 'payment provider', 'online payments', 'card'],                                           'url' => '/admin/payment_provider.php',           'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'AI Providers',             'keywords' => ['ai provider', 'model', 'openai', 'anthropic', 'prompt'],                                           'url' => '/admin/ai_provider.php',                'visible' => true],
        ['label' => 'Endpoint agent',           'keywords' => ['agent', 'endpoint', 'rmm', 'meshcentral', 'enrollment', 'remote', 'windows'],        'url' => '/admin/settings_endpoint_agent.php',    'visible' => true],
        ['label' => 'Comet Backups',            'keywords' => ['comet', 'backup status', 'backups'],                                                               'url' => '/admin/settings_comet.php',             'visible' => true],
        ['label' => 'Mailboxes',                'keywords' => ['mailbox', 'imap', 'email to ticket', 'mail requests'],                                             'url' => '/admin/mailbox.php',                    'visible' => (bool) $config_module_enable_ticketing],
        ['label' => 'SLA Policies',             'keywords' => ['sla', 'service level', 'response target', 'business hours', 'holidays'],                          'url' => '/admin/sla_policies.php',               'visible' => (bool) $config_module_enable_ticketing],
        ['label' => 'Ticket Statuses',          'keywords' => ['ticket status', 'labor types', 'ticket automation'],                                               'url' => '/admin/ticket_status.php',              'visible' => (bool) $config_module_enable_ticketing],
        ['label' => 'Backups',                  'keywords' => ['backup', 'restore', 'credential restore'],                                                         'url' => '/admin/backup.php',                     'visible' => true],
        ['label' => 'Scheduled Jobs',           'keywords' => ['cron', 'scheduled', 'jobs'],                                                                       'url' => '/admin/cron.php',                       'visible' => true],
        ['label' => 'API Keys',                'keywords' => ['api key', 'api access'],                                                'url' => '/admin/api_keys.php',                    'visible' => true],
        ['label' => 'API Documentation',       'keywords' => ['api docs', 'api reference', 'openapi'],                                 'url' => '/admin/api_docs.php',                    'visible' => true],
        ['label' => 'Users',                   'keywords' => ['user', 'technician', 'agent', 'staff'],                                 'url' => '/admin/users.php',                       'visible' => true],
        ['label' => 'Roles',                   'keywords' => ['role', 'permission'],                                                   'url' => '/admin/roles.php',                       'visible' => true],
        ['label' => 'Redis',                   'keywords' => ['redis', 'cache', 'rate limit', 'memory', 'live updates'],              'url' => '/admin/settings_redis.php',              'visible' => true],
        ['label' => 'Identity Provider (SSO)', 'keywords' => ['sso', 'saml', 'identity provider', 'single sign-on'],                    'url' => '/admin/identity_provider.php',           'visible' => (bool) $config_client_portal_enable],
    ];
}

/*
 * Returns up to $limit matching settings entries (label or keyword contains
 * $query, case-insensitive), shaped the same as every other search group's
 * rows: title/subtitle/url. Admin-only - settings aren't relevant/visible to
 * a non-admin technician, mirroring $session_is_admin checks elsewhere.
 */
function searchSettingsIndex(string $query, int $limit = 5): array {
    global $session_is_admin;

    if (empty($session_is_admin) || $query === '') {
        return [];
    }

    $needle = mb_strtolower($query);
    $matches = [];

    foreach (getSettingsSearchIndex() as $entry) {
        if (!$entry['visible']) {
            continue;
        }
        $title = $entry['label'];
        $url = $entry['url'];
        if (mb_strpos(mb_strtolower($entry['label'] . ' ' . implode(' ', $entry['keywords'])), $needle) === false) {
            // Not the page's own name: the first section, then the first card, that the query names.
            $spots = $entry['sections'] ?? [];
            foreach ($entry['sections'] ?? [] as $section) {
                foreach ($section['parts'] ?? [] as $part) {
                    $spots[] = $part;
                }
            }
            $hit = null;
            foreach ($spots as $spot) {
                if (mb_strpos(mb_strtolower($spot['label'] . ' ' . implode(' ', $spot['keywords'])), $needle) !== false) {
                    $hit = $spot;
                    break;
                }
            }
            if ($hit === null) {
                continue;
            }
            $title .= ' › ' . $hit['label'];
            $url .= '#' . $hit['anchor'];
        }
        $matches[] = [
            'title' => $title,
            'subtitle' => 'Settings',
            'url' => $url,
        ];
        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}
