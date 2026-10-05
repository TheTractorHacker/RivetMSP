<?php

/*
 * Helpers for pages and POST handlers built on RivetCore modules (Problems & Changes, Workflows).
 * Each module has a switch (settings.config_core_<module>_enabled, default on). While a switch is off the pages show a
 * plain "module is off" notice and the POST handlers refuse to act - nothing breaks and no data is touched.
 */

require_once __DIR__ . '/event_bus.php';

if (!function_exists('rivetModulePageOn')) {
    /**
     * Call after the layout is included. Returns true when the module is on; when it is off, prints a notice and returns
     * false - the page then includes the footer and exits (at page scope, since the footer needs the page's globals):
     *   if (!rivetModulePageOn('core.itsm.enabled', 'Problems & Changes')) { require_once "../includes/footer.php"; exit; }
     */
    function rivetModulePageOn(string $flag, string $label): bool
    {
        if (\RivetMSP\Core\CoreBridge::enabled($flag)) {
            return true;
        }
        echo '<div class="card card-dark"><div class="card-body text-center py-5">'
            . '<h3 class="text-secondary"><i class="fas fa-fw fa-power-off me-2"></i>' . nullable_htmlentities($label) . ' is turned off</h3>'
            . '<p class="text-muted mb-0">The module switch (<code>' . nullable_htmlentities($flag) . '</code>) is off for this install; an administrator can turn it back on.</p>'
            . '</div></div>';

        return false;
    }

    /** POST handlers: the service, or a flash message and a redirect while the module is off. */
    function rivetModuleServiceOrBail(?object $service, string $label, string $redirectTo)
    {
        if ($service === null) {
            flash_alert($label . ' is turned off', 'error');
            redirect($redirectTo);
            exit;
        }

        return $service;
    }

    function rivetItsmProblems(): \RivetCore\ITSM\ProblemService
    {
        return rivetModuleServiceOrBail(\RivetMSP\Core\CoreBridge::problems(), 'Problems & Changes', 'problems.php');
    }

    function rivetItsmChanges(): \RivetCore\ITSM\ChangeService
    {
        return rivetModuleServiceOrBail(\RivetMSP\Core\CoreBridge::changes(), 'Problems & Changes', 'changes.php');
    }

    function rivetWorkflows(): \RivetCore\Workflow\WorkflowService
    {
        return rivetModuleServiceOrBail(\RivetMSP\Core\CoreBridge::workflow(), 'Workflows', 'workflow_runs.php');
    }
}
