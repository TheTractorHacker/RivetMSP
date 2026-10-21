<?php

/*
 * Policy starter pack card (RivetCore 1.0.0-rc.26, Rmm\Policy\StarterPack\PolicyPackInstaller / Technician\PolicyPackActions): a ready-made set of check
 * policies, alerting presets, patch policies, maintenance window templates and an optional instance-wide change-monitoring preset, installed with one
 * click (agent/rmm_policies.php, handler agent/post/rmm_policy_pack.php). Administrators only (rmm.admin) - Core's own doc
 * (vendor/rivet/rivet-core/docs/rmm/POLICY_STARTER_PACK.md, section 7 "What an edition needs to build") is the spec this card follows.
 *
 * This is a DIFFERENT, newer feature from the older "RMM starter pack" (scripts / check template sets / policy drafts, rc.13,
 * agent/rmm_starter_pack.php -> includes/rmm_starter_ui.php, RivetCore\Rmm\Starter\* / Technician\StarterActions) - do not confuse the two. That page's
 * StarterActions is composed alongside this page's PolicyPackActions in the SAME RmmModule::starterApi(), but they are independent catalogs with
 * independent states; this file never touches StarterActions.
 *
 * ONE AUTHORIZATION PATH, same convention as rmm_starter_ui.php: every read and write is a direct call into
 * RivetCore\Rmm\Technician\PolicyPackActions ($rmm->policyPackActions()) as the signed-in user. Core decides who may (rmm.admin) and writes the audit
 * rows; hiding a button here is cosmetic only.
 */

use RivetCore\Rmm\Policy\StarterPack\PolicyPack;
use RivetCore\Rmm\Technician\ActionResult;

const RIVET_RMM_PP_SESSION = 'rmm_policy_pack_result';

/** A technician-API style result array from an ActionResult. @return array{ok:bool,status:int,error:string,code:string,data:array<string,mixed>} */
function rivetRmmPolicyPackResult(ActionResult $r): array
{
    return ['ok' => $r->ok, 'status' => $r->http, 'error' => $r->ok ? '' : $r->message, 'code' => $r->code, 'data' => $r->data];
}

/** @return array<string,array{0:string,1:string}> state => [pill kind, words] */
function rivetRmmPolicyPackStateWords(): array
{
    return ['not_installed' => ['off', 'Not installed'], 'installed' => ['ok', 'Installed'], 'update_available' => ['info', 'Update available'], 'edited' => ['warn', 'Edited']];
}

/** Does this description (a maintenance window's note) end with the pack marker? Used for the "Starter" badge on other lists. */
function rivetRmmPolicyPackMarked(string $description): bool
{
    return PolicyPack::parseMarker($description) !== null;
}

/** The description with the pack marker removed, for display. */
function rivetRmmPolicyPackStrip(string $description): string
{
    return PolicyPack::stripMarker($description);
}

/**
 * The view-model: the catalog grouped by `group`, each item's state words and a recommended flag (the baseline/default items: everything except the
 * instance-wide change-monitoring item and role add-ons, so "Install recommended" offers a sane starting set).
 *
 * @return array{ok:bool,error:string,pack_id:string,pack_version:string,counts:array<string,int>,groups:array<string,list<array<string,mixed>>>}|array{ok:bool,error:string}
 */
function rivetRmmPolicyPackView(\RivetCore\Rmm\RmmModule $rmm, \RivetCore\Rmm\Authz\RmmPrincipal $who): array
{
    $r = rivetRmmPolicyPackResult($rmm->policyPackActions()->catalog($who));
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'] !== '' ? $r['error'] : 'The policy starter pack could not be read.'];
    }
    $groups = [];
    foreach ((array) ($r['data']['items'] ?? []) as $it) {
        $it['state_words'] = rivetRmmPolicyPackStateWords()[(string) $it['state']] ?? ['off', ucfirst((string) $it['state'])];
        $it['live'] = (bool) ($it['enabled'] ?? false) || (int) ($it['assignments'] ?? 0) > 0;
        $it['recommended'] = empty($it['instance_wide']) && !str_contains((string) $it['group'], 'add-on');
        $groups[(string) $it['group']][] = $it;
    }

    return ['ok' => true, 'error' => '', 'pack_id' => (string) ($r['data']['pack_id'] ?? ''), 'pack_version' => (string) ($r['data']['version'] ?? ''),
        'counts' => (array) ($r['data']['counts'] ?? []), 'groups' => $groups];
}

/** The card's HTML. $flash is the last plan/install/update/reset/uninstall result (session-carried, shown once), or null. */
function rivetRmmPolicyPackCard(array $vm, string $csrf, ?array $flash): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $o = '<div class="card mb-3" id="policy-starter-pack"><div class="card-header"><h4 class="card-title mb-0">Starter policies</h4></div><div class="card-body">';
    if (!$vm['ok']) {
        return $o . '<p class="text-danger mb-0">' . $h($vm['error']) . '</p></div></div>';
    }
    $o .= '<p class="small text-muted">A ready-made set of check policies, alerting presets, patch policies and maintenance window templates'
        . ' (' . $h($vm['pack_id']) . ' ' . $h($vm['pack_version']) . '). <strong>Installing creates the policies switched off and assigned to nothing.'
        . ' Nothing changes on your devices until you switch one on and assign it.</strong></p>';
    if ($flash !== null) {
        $kind = ($flash['counts']['failed'] ?? 0) > 0 || ($flash['counts']['blocked'] ?? 0) > 0 ? 'warning' : 'success';
        $parts = [];
        foreach ((array) ($flash['counts'] ?? []) as $outcome => $n) {
            $parts[] = (int) $n . ' ' . $h(str_replace('_', ' ', (string) $outcome));
        }
        $o .= '<div class="alert alert-' . $kind . ' py-2" role="status">' . $h(ucfirst((string) ($flash['op'] ?? ''))) . ($flash['dry_run'] ?? false ? ' (preview)' : '') . ': ' . $h(implode(', ', $parts) ?: 'nothing to do') . '<ul class="mb-0 small">';
        foreach ((array) ($flash['items'] ?? []) as $it) {
            if (isset($it['reason'])) {
                $o .= '<li>' . $h($it['name']) . ': ' . $h($it['outcome']) . ' - ' . $h($it['reason']) . '</li>';
            }
        }
        $o .= '</ul></div>';
    }
    $o .= '<form method="post" action="/agent/post/rmm_policy_pack.php" id="pp-form"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
    foreach ($vm['groups'] as $group => $items) {
        $o .= '<h6 class="mt-3">' . $h($group) . '</h6><div class="table-responsive"><table class="table table-sm align-middle mb-2">'
            . '<thead><tr><th scope="col"><span class="visually-hidden">Select</span></th><th scope="col">Name</th><th scope="col">Platform</th><th scope="col">State</th><th scope="col">Summary</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($items as $it) {
            [$pillKind, $pillWords] = $it['state_words'];
            $o .= '<tr data-pp-item="' . $h($it['id']) . '"><td>';
            if ($it['state'] === 'not_installed') {
                $o .= '<input type="checkbox" class="form-check-input" name="ids[]" value="' . $h($it['id']) . '"' . ($it['recommended'] ? ' data-pp-recommended="1"' : '') . '>';
            }
            $o .= '</td><td>' . $h($it['name']) . ($it['instance_wide'] ? ' <span class="badge bg-info text-dark" title="Applies to all devices, not assigned per device">Applies to all devices</span>' : '') . '</td>'
                . '<td>' . $h(ucfirst((string) $it['platform'])) . '</td><td><span class="badge bg-' . $pillKind . '">' . $h($pillWords) . '</span>' . ($it['live'] ? ' <span class="badge bg-secondary">In use</span>' : '') . '</td>'
                . '<td>' . $h($it['summary']) . ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="collapse" data-bs-target="#pp-why-' . $h($it['id']) . '">Why?</button>'
                . '<div class="collapse small text-muted mt-1" id="pp-why-' . $h($it['id']) . '"><p class="mb-1">' . $h($it['why']) . '</p>';
            foreach ((array) ($it['why_settings'] ?? []) as $setting => $why) {
                $o .= '<div><code>' . $h($setting) . '</code>: ' . $h($why) . '</div>';
            }
            $o .= '</div></td><td class="text-nowrap">';
            if ($it['state'] === 'update_available') {
                $o .= '<button type="submit" class="btn btn-xs btn-outline-primary" name="pp_op" value="update:' . $h($it['id']) . '">Update</button> ';
            }
            if ($it['state'] !== 'not_installed' && !$it['instance_wide']) {
                $o .= '<button type="submit" class="btn btn-xs btn-outline-secondary" name="pp_op" value="reset:' . $h($it['id']) . '" data-ea-confirm="This replaces your changes to this policy with the pack version.">Reset to pack version</button> '
                    . '<button type="submit" class="btn btn-xs btn-outline-danger" name="pp_op" value="uninstall:' . $h($it['id']) . '">Uninstall</button>';
            }
            if ($it['instance_wide'] && $it['state'] === 'not_installed') {
                $o .= '<div class="form-check d-inline-block me-2"><input type="checkbox" class="form-check-input" name="confirm_instance_wide" value="1" id="pp-confirm-iw"><label class="form-check-label small" for="pp-confirm-iw">Applies to all devices</label></div>';
            }
            $o .= '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $o .= '<button type="submit" class="btn btn-sm btn-outline-primary" name="pp_op" value="install" id="pp-install-selected">Install selected</button> '
        . '<button type="button" class="btn btn-sm btn-primary" id="pp-install-recommended">Install recommended</button>'
        . '</form></div></div>';

    return $o;
}
