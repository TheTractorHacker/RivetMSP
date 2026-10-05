<?php

/** Admin directories and the detail pages each one owns. */
function itflowAdminNavAreas(): array
{
    return [
        'catalog_setup' => [
            'title' => 'Tags & categories', 'icon' => 'fa-sliders-h',
            'pages' => ['catalog_setup.php', 'tag.php', 'category.php', 'custom_link.php', 'ai_provider.php', 'ai_model.php'],
        ],
        'ticketing_setup' => [
            'title' => 'Ticketing', 'icon' => 'fa-life-ring',
            'pages' => ['ticketing_setup.php', 'ticket_status.php', 'labor_type.php', 'ticket_automation.php', 'ticket_automation_log.php', 'mailbox.php', 'mail_requests.php', 'sla_calendars.php', 'sla_policies.php', 'holidays.php'],
        ],
        'template_library' => [
            'title' => 'Templates', 'icon' => 'fa-copy',
            'pages' => ['template_library.php', 'contract_template.php', 'contract_template_details.php', 'project_template.php', 'project_template_details.php', 'onboarding_templates.php', 'onboarding_template_details.php', 'workflow_templates.php', 'workflow_template_details.php', 'ticket_template.php', 'ticket_template_details.php', 'canned_responses.php', 'worksheet_template.php', 'worksheet_template_details.php', 'vendor_template.php', 'software_template.php', 'document_template.php', 'document_template_details.php'],
        ],
        'maintenance' => [
            'title' => 'Maintenance', 'icon' => 'fa-tools',
            'pages' => ['maintenance.php', 'cron.php', 'mail_queue.php', 'email_log.php', 'audit_log.php', 'audit_trail.php', 'app_log.php', 'backup.php', 'settings_redis.php', 'debug.php', 'update.php', 'credential_restore.php'],
        ],
    ];
}

/** Pages that belong to the Settings directory (admin/settings.php); the sidebar's Settings item stays active on them. */
function itflowAdminSettingsPages(): array
{
    return ['settings.php', 'settings_company.php', 'settings_localization.php', 'settings_theme.php', 'settings_appearance.php',
        'settings_compliance.php', 'compliance_status.php', 'compliance_report.php', 'event_rules.php', 'job_queue.php',
        'settings_security.php', 'settings_mail.php', 'oauth_microsoft_mail_callback.php', 'settings_notification.php',
        'settings_default.php', 'settings_invoice.php', 'settings_quote.php', 'settings_online_payment.php', 'settings_online_payment_clients.php',
        'settings_project.php', 'settings_ticket.php', 'settings_ai.php', 'settings_custom_fields.php', 'identity_provider.php',
        'settings_telemetry.php', 'settings_module.php', 'settings_calendar_sync.php', 'settings_webhooks.php',
        'settings_integrations.php', 'settings_comet.php', 'comet_status.php', 'settings_rmm.php', 'settings_unifi.php',
        'settings_accounting.php', 'accounting_client_mapping.php', 'accounting_item_mapping.php', 'accounting_sync_status.php',
        'oauth_quickbooks_callback.php', 'oauth_quickbooks_connect.php', 'settings_kb.php'];
}
