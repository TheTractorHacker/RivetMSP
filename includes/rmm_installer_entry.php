<?php

/*
 * 26.10.30: the "Create installer" entry of the Endpoints pages. The builder (agent/rmm_add_device.php) used to be easy to miss and was simply hidden from a viewer who could not use it.
 * rivetRmmInstallerEntry() decides ONE of four states and returns the header button and a plain message, so the RMM dashboard and the Agent fleet say the same thing:
 *   ok            the viewer may build installers: a primary "Create installer" button (plus a notice when no Windows agent or MSI template is published yet)
 *   module_off    the RMM module is switched off: the button is shown disabled and the message names the switch
 *   no_permission the viewer lacks the permission: the button is shown disabled and the message names it (Create enrollment tokens, rmm.token.manage)
 *   no_service    the endpoint agent service is switched off or has no https address (the builder would only show the same problem)
 * Nothing here grants anything: the page and its handler (agent/post/rmm_installer.php) check CSRF and the permission again.
 */

require_once __DIR__ . '/rmm_ui.php';
require_once __DIR__ . '/rmm_ui_render.php';

const RIVET_INSTALLER_URL = '/agent/rmm_add_device.php';
const RIVET_AGENT_UPDATES_URL = '/admin/settings_endpoint_agent.php#agent-updates';

/**
 * @return array{state:string,button:string,notice:string,missing:?string}
 */
function rivetRmmInstallerEntry(\mysqli $mysqli, int $userId, bool $isAdmin, bool $coreFlag, string $btnClass = 'btn btn-primary'): array
{
    $e = static fn ($v): string => rmmH((string) $v);
    $adminLink = static fn (string $text, string $href): string => $isAdmin ? ' <a href="' . $e($href) . '">' . $text . '</a>.' : ' An administrator can do this.';
    $btn = static fn (bool $on, string $reason) => $on
        ? '<a href="' . RIVET_INSTALLER_URL . '" class="' . $e($btnClass) . '" data-rmm-build-installer><i class="fas fa-plus me-1" aria-hidden="true"></i>Add device</a>'
        : '<button type="button" class="' . $e(str_replace('btn-primary', 'btn-outline-secondary', $btnClass)) . '" disabled aria-disabled="true" title="' . $e($reason) . '" data-rmm-build-installer-off><i class="fas fa-plus me-1" aria-hidden="true"></i>Add device</button>';
    $note = static fn (string $kind, string $inner, string $level = 'warning'): string => '<div class="alert alert-' . $level . ' py-2 mb-3" role="status" data-rmm-installer-entry="' . $kind . '"><div><i class="fas fa-info-circle me-1" aria-hidden="true"></i>' . $inner . '</div></div>';

    if (!$coreFlag || !rivetRmmEnabled($mysqli)) {
        $why = 'The RMM module is switched off.';

        return ['state' => 'module_off', 'button' => $btn(false, $why), 'missing' => 'RMM module switched on',
            'notice' => $note('module_off', '<strong>Add device is unavailable:</strong> the RMM module is switched off. Switch it on under Administration &gt; Endpoint agent (the RMM module card).' . ($isAdmin ? ' <a href="/admin/settings_endpoint_agent.php">Open it</a>.' : ' An administrator can do this.'))];
    }
    $ins = rivetRmmUiInstaller($mysqli, $userId, null, null);
    if ($ins === null) {
        $why = 'Your role lacks the permission "Create enrollment tokens" (rmm.token.manage).';

        return ['state' => 'no_permission', 'button' => $btn(false, $why), 'missing' => 'rmm.token.manage',
            'notice' => $note('no_permission', '<strong>Add device is unavailable to you:</strong> your role lacks the permission <strong>Create enrollment tokens</strong> (<code>rmm.token.manage</code>). Ask an administrator to grant it under Administration &gt; Roles.')];
    }
    $n = '';
    $state = 'ok';
    if ($ins['service_problem'] !== null) {
        $state = 'no_service';
        $n .= $note('no_service', '<strong>Installers cannot be built yet:</strong> ' . $e($ins['service_problem']) . $adminLink('Fix it in Administration &gt; Endpoint agent', '/admin/settings_endpoint_agent.php'));
    } elseif ($ins['windows']['amd64'] === null && $ins['windows']['arm64'] === null) {
        $n .= $note('no_agent', '<strong>No Windows agent is published yet,</strong> so a Windows installer cannot be built (Linux commands still work once a release is published).'
            . $adminLink('Publish one under Administration &gt; Endpoint agent &gt; Agent updates', RIVET_AGENT_UPDATES_URL));
    } elseif ($ins['msi']['amd64'] === null && $ins['msi']['arm64'] === null) {
        $n .= $note('no_msi', 'No MSI template is published, so only the .exe installer can be built (the .msi format stays disabled).'
            . $adminLink('Publish it under Administration &gt; Endpoint agent &gt; Agent updates', RIVET_AGENT_UPDATES_URL), 'info');
    }

    return ['state' => $state, 'button' => $btn(true, ''), 'notice' => $n, 'missing' => null];
}
