<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Remote access (MeshCentral) setup wizard: form handlers. admin/post.php runs this file for administrators only (Referer basename = rmm_mesh_wizard). Every validation,
 * authorization and audit entry is RivetCore (RmmAdmin::saveMesh needs rmm.admin); this file reads the step form, merges it onto the saved settings and shows Core's refusal.
 * "Read it from the server" only PROPOSES a pin (nothing is saved).
 */

require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_scale_admin.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_mesh_wizard.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (isset($_POST['wiz_save1']) || isset($_POST['wiz_test']) || isset($_POST['wiz_save2']) || isset($_POST['wiz_save3']) || isset($_POST['wiz_probe'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $rmm = rivetRmmModule();
    $ea_admin = $rmm->admin();
    $ea_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);

    if (isset($_POST['wiz_probe'])) {
        $r = $rmm->remoteActions()->updateMeshSettings($ea_who, ['probe_pin' => true]);
        $_SESSION['rmm_mesh_wiz_probe'] = $r->ok && is_array($r->data['pin_probe'] ?? null) ? $r->data['pin_probe'] : ['ok' => false, 'error' => $r->ok ? 'The certificate could not be read.' : $r->message];
        redirect('rmm_mesh_wizard.php#step3');
    }
    $cfg = $rmm->readModel()->settingsSummary();
    [$in, , $old] = rivetRmmMeshWizardRead($_POST, $cfg);
    $step = isset($_POST['wiz_save2']) ? 2 : (isset($_POST['wiz_save3']) ? 3 : 1);
    $r = $ea_admin->saveMesh($ea_who, $in, isset($_POST['wiz_test']));
    $rmm->syncState();   // the remote sub-switch follows mesh_enabled
    if (isset($_POST['wiz_test'])) {
        $_SESSION['rmm_mesh_wiz_test'] = ['ok' => $r->ok, 'text' => $r->ok ? 'MeshCentral answered.' : mb_substr((string) $r->message, 0, 300), 'at' => time()];
    }
    if (!$r->ok && ($r->code === 'invalid')) {
        rivetRmmScaleMsg('mesh_wizard', 'error', $r->message, $old);
    } elseif (!$r->ok) {
        rivetRmmScaleMsg('mesh_wizard', 'warning', 'Saved, but: ' . $r->message);
    } else {
        $warn = is_array($r->data['warnings'] ?? null) ? array_values(array_filter($r->data['warnings'], 'is_string')) : [];
        rivetRmmScaleMsg('mesh_wizard', $warn !== [] ? 'warning' : 'success', $warn !== [] ? 'Saved, with a warning: ' . implode(' ', $warn) : $r->message);
    }
    redirect('rmm_mesh_wizard.php#step' . $step);
}
