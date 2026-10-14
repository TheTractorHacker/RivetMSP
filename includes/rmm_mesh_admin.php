<?php

/*
 * Administration > Endpoint agent > MeshCentral: the install settings of the optional MeshCentral agent (RivetCore 1.0.0-rc.12, migration 0023; the group id in MeshCentral's URL form and the pin guidance came with rc.15, migration 0024).
 * The device group the devices join, the optional pin of the Mesh server's certificate (with a button that READS the certificate and only PROPOSES a pin), the switch that lets an
 * automatically found node id replace one an administrator mapped by hand, and for experts the corrections to the agent-id and install-argument tables.
 *
 * Plain functions. The card's fields are rendered here (no query); the page (admin/settings_endpoint_agent.php) reads the data with rivetRmmMeshInstallView() and the handler
 * (admin/post/settings_endpoint_agent.php) turns the form into the array RmmAdmin::saveMesh() takes with rivetRmmMeshInstallRead(). Core validates, authorizes and audits;
 * nothing is saved by the pin probe. The device group id is a secret (anyone holding it who can reach the Mesh server can download an agent that joins the group), so its field is a
 * masked password input. It accepts what Core accepts (the whole pasted download address, the bare 64-character meshid, mesh//..., hex) and is sent to Core UNCHANGED beyond
 * trimming whitespace: this edition does not restrict it to hex.
 */

require_once __DIR__ . '/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\RmmModule;

/**
 * The Mesh install settings as Core's RemoteActions::meshSettings() reports them, or null (not an administrator, or the Mesh tables are not there yet).
 *
 * @return array<string,mixed>|null
 */
function rivetRmmMeshInstallView(RmmModule $rmm, RmmPrincipal $who): ?array
{
    try {
        $r = $rmm->remoteActions()->meshSettings($who);
    } catch (\Throwable) {
        return null;
    }

    if (!$r->ok) {
        return null;
    }
    // RivetCore 1.0.0-rc.17: the hide values can only be saved once migration 0026 is applied; Core's view reports the defaults either way, so ask the settings.
    $d = $r->data;
    $d['hide_available'] = $rmm->meshAgentSettings()->hideAvailable();

    return $d;
}

/**
 * The install fields of the Mesh form, read back from $_POST, in the shape RmmAdmin::saveMesh() takes. Returns [fields, errors, old]: `fields` is empty when the form did not carry the
 * install fields at all (an older form, a script): nothing is then changed. `old` is what the person typed, to put back into the form when something is refused.
 *
 * @param array<string,mixed> $post
 * @return array{0:array<string,mixed>,1:list<string>,2:array<string,string>}
 */
function rivetRmmMeshInstallRead(array $post): array
{
    // RivetCore 1.0.0-rc.17: how much of MeshCentral's interface the new tab shows; sent exactly as typed, Core refuses anything but a whole number 0 to 255 (the second may be empty = same).
    // Independent of the install fields: the card prints them whenever the database has migration 0026.
    $hideFields = [];
    $hideOld = [];
    if (($post['mesh_hide_present'] ?? '') === '1') {
        $hideOld = ['hide' => is_string($post['mesh_hide'] ?? null) ? trim($post['mesh_hide']) : '', 'hide_tools' => is_string($post['mesh_hide_tools'] ?? null) ? trim($post['mesh_hide_tools']) : ''];
        $hideFields = ['mesh_hide' => $hideOld['hide'], 'mesh_hide_tools' => $hideOld['hide_tools']];
    }
    if (($post['mesh_install_fields'] ?? '') !== '1') {
        return [$hideFields, [], $hideOld];
    }
    $str = static fn (string $k, int $max): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $old = ['group_id' => $str('mesh_group_id', 2001), 'pin' => $str('mesh_server_cert_pin', 300), 'overwrite' => !empty($post['mesh_auto_map_overwrite']) ? '1' : '',
        'agent_ids' => is_string($post['mesh_agent_ids'] ?? null) ? mb_substr((string) $post['mesh_agent_ids'], 0, 4000) : '',
        'install_args' => is_string($post['mesh_install_args'] ?? null) ? mb_substr((string) $post['mesh_install_args'], 0, 4000) : ''];
    $fields = ['mesh_group_id' => $old['group_id'], 'mesh_server_cert_pin' => $old['pin'], 'mesh_auto_map_overwrite' => $old['overwrite'] === '1'];
    $errors = [];
    foreach (['agent_ids' => ['mesh_agent_ids', 'The agent id corrections'], 'install_args' => ['mesh_install_args', 'The install argument corrections']] as $k => [$field, $label]) {
        $text = trim($old[$k]);
        if ($text === '') {
            $fields[$field] = [];   // empty = no corrections, Core's built-in table applies
            continue;
        }
        $d = json_decode($text, true);
        if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = $label . ' must be a JSON object, for example {"windows/amd64": 3}, or empty to use the built-in table.';
            continue;
        }
        $fields[$field] = $d;
    }

    return [$fields + $hideFields, $errors, $old + $hideOld];
}

/** The proposal the pin probe produced, as the card shows it (text escaped here). @param array<string,mixed>|null $probe */
function rivetRmmMeshPinProposalHtml(?array $probe): string
{
    if ($probe === null) {
        return '';
    }
    if (empty($probe['ok'])) {
        return '<div class="alert alert-warning py-2 mb-3" role="alert" tabindex="-1" data-rmm-mesh-pin-result="error"><div><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
            . nullable_htmlentities((string) ($probe['error'] ?? 'The certificate could not be read.')) . '</div></div>';
    }
    $pin = (string) ($probe['pin'] ?? '');
    $note = (string) ($probe['note'] ?? '');
    $trusted = !empty($probe['publicly_trusted']);
    $meta = (!empty($probe['subject']) ? '<p class="mb-1 small">Subject: ' . nullable_htmlentities((string) $probe['subject']) . '</p>' : '')
        . (!empty($probe['not_after']) ? '<p class="mb-1 small">Valid until: ' . nullable_htmlentities(str_replace('T', ' ', rtrim((string) $probe['not_after'], 'Z'))) . ' UTC</p>' : '');
    if ($trusted) {
        // A certificate from a public authority renews every 60 to 90 days (Let's Encrypt): a pin of it would break at each renewal. Show Core's note and offer NO way to save the pin.
        return '<div class="alert alert-success py-2 mb-3" role="status" tabindex="-1" data-rmm-mesh-pin-result="trusted"><div>'
            . '<p class="mb-1"><i class="fas fa-check-circle me-1" aria-hidden="true"></i><strong>' . nullable_htmlentities($note !== '' ? $note : 'This certificate is publicly trusted: no pin needed. Leave the pin empty so installs keep working when the certificate is renewed.') . '</strong></p>'
            . '<p class="mb-1 small">Nothing has been saved. Leave the pin field empty.</p>' . $meta . '</div></div>';
    }
    $short = '';
    $na = (string) ($probe['not_after'] ?? '');
    if ($na !== '' && ($t = strtotime($na)) !== false && $t - time() < 100 * 86400) {
        $short = '<p class="small mb-2 text-warning-emphasis"><i class="fas fa-clock me-1" aria-hidden="true"></i>This certificate expires soon (' . nullable_htmlentities(gmdate('Y-m-d', $t))
            . '). A pin on a certificate that is renewed often breaks at every renewal; pin a long-lived self-signed certificate, or leave the pin empty for a certificate from a public authority.</p>';
    }

    return '<div class="alert alert-info py-2 mb-3" role="status" tabindex="-1" data-rmm-mesh-pin-result="ok"><div><p class="mb-1"><strong>The server presented this certificate.</strong> Nothing has been saved. '
        . 'Trust on first use: compare the hash with the one MeshCentral shows (My Server, certificate hashes) or with <code>openssl x509 -noout -fingerprint -sha384</code> run from a machine you trust. '
        . 'If it matches, use it, then press Save.</p>'
        . ($note !== '' ? '<p class="mb-1" data-rmm-mesh-pin-note>' . nullable_htmlentities($note) . '</p>' : '')
        . '<p class="mb-1">SHA-384: <code class="user-select-all text-break" data-rmm-mesh-pin-value>' . nullable_htmlentities($pin) . '</code></p>'
        . (!empty($probe['sha256']) ? '<p class="mb-1 small">SHA-256: <code class="user-select-all text-break">' . nullable_htmlentities((string) $probe['sha256']) . '</code></p>' : '')
        . $meta
        . $short
        . '<button type="button" class="btn btn-sm btn-outline-primary" data-rmm-mesh-use-pin="' . nullable_htmlentities($pin) . '"><i class="fas fa-arrow-down me-1" aria-hidden="true"></i>Put this hash in the pin field</button>'
        . '</div></div>';
}

/**
 * The install fields of the Mesh card (inserted into the Mesh form, before its buttons) and the separate form of the pin button. `$v` is rivetRmmMeshInstallView(), null when it
 * could not be read. `$old` is the refused form. The probe form is returned separately because HTML forms cannot nest: the button points at it with the `form` attribute.
 *
 * @param array<string,mixed>|null $v
 * @param array<string,string> $old
 * @return array{fields:string,probe_form:string}
 */
function rivetRmmMeshInstallCard(?array $v, array $old, ?array $probe, string $csrf, bool $urlSaved): array
{
    if ($v === null) {
        return ['fields' => '<div class="col-12"><div class="alert alert-secondary py-2 mb-0" data-rmm-mesh-install="unavailable">The MeshCentral agent install settings are not available yet. Run the database update (Administration, Updates), then reload this page.</div></div>', 'probe_form' => ''];
    }
    // Read back the form MeshCentral printed (mesh_group_id_url; derived by Core from the hex for older rows); the canonical hex stays in mesh_group_id.
    $group = array_key_exists('group_id', $old) ? $old['group_id'] : (string) (($v['mesh_group_id_url'] ?? '') !== '' ? $v['mesh_group_id_url'] : ($v['mesh_group_id'] ?? ''));
    $pin = array_key_exists('pin', $old) ? $old['pin'] : (string) $v['mesh_server_cert_pin'];
    $over = array_key_exists('overwrite', $old) ? $old['overwrite'] === '1' : !empty($v['mesh_auto_map_overwrite']);
    $ids = array_key_exists('agent_ids', $old) ? $old['agent_ids'] : ((array) $v['mesh_agent_ids'] === [] ? '' : (string) json_encode($v['mesh_agent_ids'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $args = array_key_exists('install_args', $old) ? $old['install_args'] : ((array) $v['mesh_install_args'] === [] ? '' : (string) json_encode($v['mesh_install_args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $ready = !empty($v['install_ready']);
    $state = $ready ? '<span class="badge text-bg-success">Installers can include the MeshCentral agent</span>'
        : '<span class="badge text-bg-secondary">Not ready</span> <span class="small text-muted">' . nullable_htmlentities((string) ($v['install_refusal'] ?? '')) . '</span>';
    $probeDisabled = $urlSaved ? '' : ' disabled aria-disabled="true"';
    $e = static fn ($x): string => nullable_htmlentities((string) $x);

    $hide = '';
    if (!empty($v['hide_available'])) {
        $hv = array_key_exists('hide', $old) ? (string) $old['hide'] : (string) ($v['mesh_hide'] ?? 31);
        $ht = array_key_exists('hide_tools', $old) ? (string) $old['hide_tools'] : (($v['mesh_hide_tools'] ?? null) === null ? '' : (string) $v['mesh_hide_tools']);
        $hide = '<input type="hidden" name="mesh_hide_present" value="1">'
            . '<div class="col-lg-6"><label class="form-label" for="mc_hide">Hide MeshCentral interface elements <span class="text-muted">(0 to 255)</span></label>'
            . '<input type="number" class="form-control" id="mc_hide" name="mesh_hide" min="0" max="255" step="1" value="' . $e($hv) . '" aria-describedby="mc_hide_help">'
            . '<div class="form-text" id="mc_hide_help">31 = show only the remote screen (no MeshCentral header, tabs, footer or device list), 0 = full MeshCentral. It is MeshCentral\'s <code>hide</code> bitmask: 1 header, 2 top bar, 4 footer, 8 titles, 16 left bar and device list, 32 back button. MeshCentral does not connect by itself: click <em>Connect</em> in the new tab. Not yet checked against a live MeshCentral version: if a bit hides something else on yours, change the number.</div></div>'
            . '<div class="col-lg-6"><label class="form-label" for="mc_hide_tools">Hide for Terminal and Files <span class="text-muted">(optional)</span></label>'
            . '<input type="number" class="form-control" id="mc_hide_tools" name="mesh_hide_tools" min="0" max="255" step="1" value="' . $e($ht) . '" placeholder="same as the desktop" aria-describedby="mc_hide_tools_help">'
            . '<div class="form-text" id="mc_hide_tools_help">Empty = the same value as above. 0 opens the Terminal and Files views inside the full MeshCentral interface.</div></div>';
    }
    $f = $hide . '<div class="col-12 border-top pt-3"><h5 class="h6 mb-1"><i class="fas fa-download me-1" aria-hidden="true"></i>Install the MeshCentral agent with the RivetMSP agent <span class="text-muted fw-normal">(optional)</span></h5>'
        . '<p class="small text-muted mb-2">When these are set, the Add device dialog and the deployment forms offer a tick-box <em>Also install the MeshCentral agent</em> (off by default). The installer never contains a Mesh binary: after the RivetMSP agent is in place it downloads the agent for <strong>your</strong> Mesh server and device group over https. '
        . 'A failure never fails the RivetMSP install. Afterwards the device\'s Mesh node is mapped to it automatically, so Remote access works without pasting a node id. <span data-rmm-mesh-ready>' . $state . '</span></p>'
        . '<input type="hidden" name="mesh_install_fields" value="1"></div>'
        . '<div class="col-lg-6"><label class="form-label" for="mc_group">Device group id <span class="badge bg-warning text-dark">secret</span></label>'
        . '<input type="password" class="form-control font-monospace" id="mc_group" name="mesh_group_id" maxlength="2000" autocomplete="off" spellcheck="false" value="' . $e($group) . '" aria-describedby="mc_group_h">'
        . '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="mc_group_show" data-rmm-mesh-show="mc_group"><label class="form-check-label small" for="mc_group_show">Show the group id</label></div>'
        . '<div class="form-text" id="mc_group_h">Paste the download address from MeshCentral (device group, Add Agent, Windows 64-bit installer) or just its <code>meshid</code>. It is 64 characters, not hex. Treat it like an invitation key: anyone who has it and can reach your Mesh server can download an agent that joins the group.</div></div>'
        . '<div class="col-lg-6"><label class="form-label" for="mc_pin">Mesh server certificate pin</label>'
        . '<div class="input-group"><input type="text" class="form-control font-monospace" id="mc_pin" name="mesh_server_cert_pin" maxlength="300" autocomplete="off" spellcheck="false" value="' . $e($pin) . '" placeholder="empty = system roots" aria-describedby="mc_pin_h">'
        . '<button type="submit" form="mesh-pin-probe" class="btn btn-outline-secondary" formnovalidate' . $probeDisabled . '><i class="fas fa-search me-1" aria-hidden="true"></i>Read it from the server</button></div>'
        . '<div class="form-text" id="mc_pin_h">Leave empty when the server certificate is issued by a public CA such as Let\'s Encrypt (the system roots verify it; such a certificate is renewed every 60 to 90 days and a pin of it would break). Use a pin only for a private CA or a self-signed certificate (SHA-384, 96 hex, or SHA-256, 64 hex). The button connects to the saved address, shows the certificate and <strong>never saves a pin</strong>; for a self-signed certificate compare the hash, then press Save here.'
        . ($urlSaved ? '' : ' Save the MeshCentral address first.') . '</div></div>'
        . '<div class="col-12"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="mc_over" name="mesh_auto_map_overwrite" value="1"' . ($over ? ' checked' : '') . ' aria-describedby="mc_over_h"><label class="form-check-label" for="mc_over">Let the agent\'s node id replace a node an administrator mapped by hand</label></div>'
        . '<div class="form-text" id="mc_over_h">Off (recommended): a node set by hand is kept and the device shows a hint when the agent reports a different one. A node already mapped to another live device is never taken over, whatever this switch says.</div></div>'
        . '<div class="col-12"><details class="rmm-mesh-advanced" data-rmm-mesh-advanced' . ($ids !== '' || $args !== '' ? ' open' : '') . '><summary class="fw-semibold">Advanced: agent ids and install arguments</summary>'
        . '<div class="alert alert-warning py-2 my-2" role="note"><div><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . $e((string) ($v['note'] ?? 'The agent ids, download address and install arguments were not verified against a real MeshCentral server: check them against your Mesh version.'))
        . ' Verify these against your own Mesh server before rolling out to a fleet. Leave both boxes empty to use the built-in tables.</div></div>'
        . '<div class="row g-3"><div class="col-lg-6"><label class="form-label" for="mc_ids">Agent id corrections (JSON)</label><textarea class="form-control font-monospace" id="mc_ids" name="mesh_agent_ids" rows="5" maxlength="4000" spellcheck="false" aria-describedby="mc_ids_h" placeholder="{&quot;windows/amd64&quot;: 3}">' . $e($ids) . '</textarea>'
        . '<div class="form-text" id="mc_ids_h">Keys like <code>windows/amd64</code> or <code>linux/arm64</code>, values 1 to 255. Built-in table: <code class="text-break">' . $e((string) json_encode($v['default_agent_ids'] ?? [], JSON_UNESCAPED_SLASHES)) . '</code></div></div>'
        . '<div class="col-lg-6"><label class="form-label" for="mc_args">Install argument corrections (JSON)</label><textarea class="form-control font-monospace" id="mc_args" name="mesh_install_args" rows="5" maxlength="4000" spellcheck="false" aria-describedby="mc_args_h" placeholder="{&quot;linux&quot;: [&quot;-install&quot;]}">' . $e($args) . '</textarea>'
        . '<div class="form-text" id="mc_args_h">Effective now: <code class="text-break">' . $e((string) json_encode($v['effective_install_args'] ?? [], JSON_UNESCAPED_SLASHES)) . '</code></div></div></div></details></div>'
        . '<div class="col-12" id="mesh-pin-proposal" aria-live="polite">' . rivetRmmMeshPinProposalHtml($probe) . '</div>';

    $form = '<form id="mesh-pin-probe" action="post.php" method="post" class="d-none"><input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="probe_mesh_pin" value="1"></form>';

    return ['fields' => $f, 'probe_form' => $form];
}
