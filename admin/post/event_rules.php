<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetCore\Automation\AutomationRuleStore;

require_once __DIR__ . '/../../includes/event_rules_lib.php';

$event_rules_store = static function () use ($mysqli): ?AutomationRuleStore {
    return class_exists(AutomationRuleStore::class) && rivetTableExists($mysqli, 'automation_rules') ? new AutomationRuleStore(rivetCoreDb($mysqli)) : null;
};

if (isset($_POST['save_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $store = $event_rules_store();
    if (!$store) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : null;
    // The page itself saves over fetch (admin/event_rules_tools.php); this classic post is the no-JavaScript path and the one scripts use.
    $result = eventRulesSave($mysqli, $store, $_POST, $id, (string) $session_name, (int) $session_user_id);
    if (!$result['ok']) {
        flash_alert(implode(' ', array_values($result['errors'])), 'error');
        redirect($id ? "event_rules.php?edit=$id" : 'event_rules.php');
    }
    flash_alert('Rule saved.');
    redirect('event_rules.php');
}

if (isset($_POST['toggle_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();
    if ($store = $event_rules_store()) {
        $id = intval($_POST['rule_id'] ?? 0);
        $rule = $store->find($id);
        if ($rule) {
            $store->setEnabled($id, !$rule['is_enabled']);
            logAction('Automation', 'Edit', "$session_name turned event rule $id " . ($rule['is_enabled'] ? 'off' : 'on'));
            rivetAudit('automation.rule_toggled', (int) $session_user_id, 'automation_rule', $id, 'update', 'Event rule turned ' . ($rule['is_enabled'] ? 'off' : 'on'));
        }
    }
    redirect('event_rules.php');
}

if (isset($_GET['delete_event_rule'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    validateAdminRole();
    if ($store = $event_rules_store()) {
        $id = intval($_GET['delete_event_rule']);
        $store->delete($id);
        logAction('Automation', 'Delete', "$session_name deleted event rule $id");
        rivetAudit('automation.rule_deleted', (int) $session_user_id, 'automation_rule', $id, 'delete', 'Event rule deleted');
    }
    flash_alert('Rule deleted.');
    redirect('event_rules.php');
}
