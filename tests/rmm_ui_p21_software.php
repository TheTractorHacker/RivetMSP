<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 5 UI, RivetCore 1.0.0-rc.21): software deployment and reporting. Shares that file's variables. Driven through the product: the pages, the
 * form handler (agent/post/rmm_software.php, CSRF), the CSV endpoint and the administration card over real HTTP with forged sessions, the technician API underneath (RivetCore's
 * DeployApi), the real module on the scratch database. A device's answer (what it has, the result of a job) is written straight into the package tables (the agent side is
 * Core's own proof); everything else goes through the handler. The `software_deploy` switch is OFF at the start (the default) and is put back OFF at the end.
 *
 * Users: admin (1), tech (10: rmm 3, scripts 3: may view, manage and deploy), rebootonly (11: scripts 2: view and manage, no deploy), viewer (12) and remoteonly (13) (view only),
 * stock (14: no RMM rows: nothing), clientb (16: a tech limited to Client B).
 */

use RivetCore\Rmm\Authz\RmmAbility;

$swGet = fn (string $who, string $path, array $h = []) => web($wb, 'GET', $path, $SS[$who], [], $h);
$swPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_software.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$swJson = fn (string $who, array $f) => $swPost($who, $f, ['Accept: application/json']);
$swLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$swRows = fn (string $sql) => $rows($sql);
$swNav = fn (?int $uid = null) => array_values(array_filter(array_column(rivetRmmAutoNav($uid), 'href'), fn ($h) => str_contains($h, 'rmm_software')));
$swWin = $D['WIN1'];
$swLnx = $D['LNX1'];
$swWinAsset = $A['WIN1'];
$swLnxAsset = $A['LNX1'];
$swNow = gmdate('Y-m-d H:i:s');
$swHost = 'downloads.example.com';
$swSha = str_repeat('ab', 32);
$swHostile = '<script>alert("sw")</script> & <img src=x onerror=alert(1)>';
$swPages = ['/agent/rmm_software_catalog.php', '/agent/rmm_software_assignments.php', '/agent/rmm_software_report.php'];
$swPkgId = fn (string $name) => (int) $one("SELECT package_id FROM rmm_packages WHERE name='" . $esc($name) . "'");
$swJobs = fn (int $dev, string $type) => (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$dev AND type='$type'");
$swWipe = function () use ($q): void {
    foreach (['rmm_package_history', 'rmm_package_jobs', 'rmm_package_installs', 'rmm_package_assignments', 'rmm_package_versions', 'rmm_packages', 'rmm_software_hosts', 'rmm_deploy_state'] as $t) {
        $q("DELETE FROM $t");
    }
    $q("DELETE FROM rmm_approvals WHERE kind='software'");
    $q("DELETE FROM endpoint_agent_jobs WHERE type IN ('software_detect','software_install')");
};
$swWipe();
$msi = ['action' => 'add_version', 'fmt' => 'msi', 'version' => '2.4.1', 'url' => "https://$swHost/app-2.4.1.msi", 'sha256' => strtoupper($swSha), 'size_bytes' => '1048576', 'product_code' => '{12345678-abcd-1234-abcd-1234567890ab}', 'detect_min' => '2.4.1'];

// ---- the starting state: module on, software_deploy OFF (the default)
$ok($rmm->enabled() && !$rmm->featureOn('software_deploy') && !rivetRmmFeatureOn('software_deploy'), 'software: the module is on and the software_deploy switch is off at the start');
$ok($swNav() === [] && $swNav($USER['admin']) === [], 'menu: no software entries while the switch is off');
foreach (array_merge($swPages) as $path) {
    [$c, $body] = $swGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off'), "$path: 403 and the switched-off notice while the switch is off ($c)");
}
[$c, $b] = $swGet('admin', '/agent/rmm_software_report_csv.php');
$ok($c === 404, 'csv: 404 while the switch is off (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'detect', 'device_id' => $swWin]);
$j = json_decode($b, true);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'handler (JSON): the switched-off refusal is a 404 with a message');
[$c, $body] = $swGet('admin', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok($c === 200 && !str_contains($body, 'id="rmm-tab-deploy"'), 'switch off: no Deployment tab on the asset page');
$ctxA = ['uid' => 1, 'name' => 'admin', 'mysqli' => $db, 'csrf' => ''];
$r = rivetRmmSoftwareApi($ctxA, 'POST', ['software_packages'], [], ['name' => 'Early']);
$ok(!$r['ok'] && (int) $one("SELECT COUNT(*) FROM rmm_packages") === 0, 'switch off: Core refuses to create a package and nothing changes (' . $r['status'] . ')');

// ---- switch on
$feat = $rmm->settings()->features();
$feat['software_deploy'] = true;
$r = $rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();
$ok($r->ok && $rmm->featureOn('software_deploy') && rivetRmmFeatureOn('software_deploy'), 'software: switched on through RmmAdmin');
$ok(count($swNav()) === 3 && $swNav($USER['admin']) === $swNav() && $swNav($USER['viewer']) === $swNav() && $swNav($USER['stock']) === [], 'menu: three software entries for a user who may view software status, none for a technician with no RMM role rows');

// ---- the access matrix on the pages
foreach ($swPages as $path) {
    foreach (['admin', 'tech', 'rebootonly', 'viewer', 'remoteonly', 'clientb'] as $who) {
        [$c, $body] = $swGet($who, $path);
        $ok($c === 200 && !str_contains($body, 'switched off') && !str_contains($body, 'Fatal error') && !str_contains($body, 'Stack trace'), "$path: $who can open it ($c)");
    }
    [$c, $body] = $swGet('stock', $path);
    $ok($c === 403, "$path: a technician with no RMM role rows is refused ($c)");
}
[$c, $body] = $swGet('admin', '/agent/rmm_software_catalog.php');
$ok(str_contains($body, 'No package yet') && str_contains($body, 'No host is allowed yet') && str_contains($body, 'Allow downloads from'), 'catalog page: empty states and the host form for an administrator');
[$c, $body] = $swGet('tech', '/agent/rmm_software_catalog.php');
$ok(str_contains($body, 'New package') && !str_contains($body, 'Allow downloads from'), 'catalog page: a technician may manage packages but is not offered the host list form');
[$c, $body] = $swGet('viewer', '/agent/rmm_software_catalog.php');
$ok(!str_contains($body, 'New package') && !str_contains($body, 'name="action" value="save_package"') && !str_contains($body, 'Allow downloads from'), 'catalog page: a view-only role sees no form and no New package button');

// ---- the handler: CSRF, method, unknown action
[$c] = web($wb, 'POST', '/agent/post/rmm_software.php', $SS['admin'], ['csrf_token' => 'wrong', 'action' => 'save_package', 'name' => 'NoCsrf']);
[$c2] = web($wb, 'POST', '/agent/post/rmm_software.php', $SS['admin'], ['action' => 'save_package', 'name' => 'NoCsrf']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_packages WHERE name='NoCsrf'") === 0, 'handler: a wrong or missing CSRF token changes nothing (' . $c . ', ' . $c2 . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_software.php', $SS['admin']);
$ok($c === 405, 'handler: GET is refused (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'no_such_action']);
$ok($c === 422 && str_contains($b, 'Unknown action'), 'handler: an unknown action is refused');

// ---- the host list: administrators only
[$c, $b] = $swJson('tech', ['action' => 'add_host', 'host' => $swHost]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 0, 'hosts: a technician cannot allow a host (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'add_host', 'host' => $swHost]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 0, 'hosts: a technician limited to one client cannot either (' . $c . ')');
foreach (['https://evil.example/x', 'a.example.com/path', '127.0.0.1', '10.0.0.1', 'localhost', '*.example.com', 'a.example.com:8443', "a.example.com\nb.example.com", 'user@a.example.com', ''] as $badHost) {
    [$c, $b] = $swJson('admin', ['action' => 'add_host', 'host' => $badHost]);
    $ok($c === 422 || $c === 403, 'hosts: ' . json_encode($badHost) . ' is refused (' . $c . ')');
}
$ok((int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 0, 'hosts: none of the hostile spellings was stored');

// ---- the catalog: packages
[$c, $b] = $swJson('viewer', ['action' => 'save_package', 'name' => 'Viewer pkg']);
$ok($c === 403 && $swPkgId('Viewer pkg') === 0, 'packages: a view-only role cannot create one (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'save_package', 'name' => 'ClientB pkg']);
$ok($c === 403 && $swPkgId('ClientB pkg') === 0, 'packages: a technician limited to one client cannot change the fleet-wide catalog (' . $c . ')');
[$c, $b] = $swJson('stock', ['action' => 'save_package', 'name' => 'Stock pkg']);
$ok(in_array($c, [403, 404], true) && $swPkgId('Stock pkg') === 0, 'packages: a technician with no RMM role rows is refused (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'save_package', 'name' => '']);
$ok($c === 422 && str_contains($b, 'Give the package a name'), 'packages: a package needs a name');
[$c, $b] = $swJson('admin', ['action' => 'save_package', 'name' => "Bad\x07name"]);
$ok($c === 422 && (int) $one("SELECT COUNT(*) FROM rmm_packages") === 0, 'packages: a control character in the name is refused (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'save_package', 'name' => 'Acme Agent', 'publisher' => $swHostile, 'description' => 'Test package', 'enabled' => '1']);
$pkgA = $swPkgId('Acme Agent');
$ok($c === 200 && $pkgA > 0 && $one("SELECT publisher FROM rmm_packages WHERE package_id=$pkgA") === $swHostile, 'packages: a technician with RMM scripts level 3 creates a package (hostile publisher text stored as text) (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'save_package', 'name' => 'Acme Agent']);
$ok($c === 422 && str_contains($b, 'exists'), 'packages: a duplicate name is refused (' . $c . ')');
[$c, $b] = $swJson('rebootonly', ['action' => 'save_package', 'name' => $swHostile, 'enabled' => '1']);
$pkgH = $swPkgId($swHostile);
$ok($c === 200 && $pkgH > 0, 'packages: a role with RMM scripts level 2 (manage) may create one too; the hostile name is plain text (' . $c . ')');
[$c, $body] = $swGet('admin', '/agent/rmm_software_catalog.php');
$ok(!str_contains($body, '<script>alert("sw")') && !str_contains($body, '<img src=x onerror') && str_contains($body, '&lt;script&gt;alert(&quot;sw&quot;)&lt;/script&gt;'), 'catalog page: a hostile package name and publisher are escaped');
[$c, $body] = $swGet('admin', "/agent/rmm_software_catalog.php?package_id=$pkgA");
$ok($c === 200 && str_contains($body, 'Edit package') && str_contains($body, 'value="Acme Agent"') && str_contains($body, 'Add a version'), 'catalog page: opening a package shows its form and the add-version form');
[$c, $body] = $swGet('admin', '/agent/rmm_software_catalog.php?package_id=99999&new=1');
$ok($c === 200 && str_contains($body, 'does not exist') && !str_contains($body, 'Fatal error'), 'catalog page: an unknown package id is a message');
[$c, $body] = $swGet('admin', '/agent/rmm_software_catalog.php?package_id=1%27%20OR%201=1');
$ok($c === 200 && !str_contains($body, 'Fatal error'), 'catalog page: a non-numeric package id is ignored');

// ---- the catalog: versions (an installer is pinned by host, hash and size; a version is never edited)
[$c, $b] = $swJson('tech', ['package_id' => $pkgA] + $msi);
$ok($c === 422 && str_contains($b, 'list of hosts') && (int) $one("SELECT COUNT(*) FROM rmm_package_versions") === 0, 'versions: a Windows version is refused while its host is not on the list (' . $c . ')');
$swJson('admin', ['action' => 'add_host', 'host' => strtoupper($swHost), 'note' => 'Vendor CDN']);
$ok($one("SELECT host FROM rmm_software_hosts") === $swHost && (int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 1, 'hosts: an administrator allows a host (stored in lower case)');
$badVersions = [
    'http address' => ['url' => "http://$swHost/a.msi"],
    'other host' => ['url' => 'https://other.example.com/a.msi'],
    'lookalike' => ['url' => "https://$swHost.evil.example/a.msi"],
    'IP address' => ['url' => 'https://127.0.0.1/a.msi'],
    'user information' => ['url' => "https://x@$swHost/a.msi"],
    'a port' => ['url' => "https://$swHost:8443/a.msi"],
    'a query string' => ['url' => "https://$swHost/a.msi?sig=SECRET"],
    'a fragment' => ['url' => "https://$swHost/a.msi#x"],
    'no hash' => ['sha256' => ''],
    'short hash' => ['sha256' => 'abc'],
    'non hex hash' => ['sha256' => str_repeat('g', 64)],
    'no size' => ['size_bytes' => ''],
    'text size' => ['size_bytes' => '12x'],
    'huge size' => ['size_bytes' => '5000000000'],
    'no product code' => ['product_code' => ''],
    'bad product code' => ['product_code' => '12345678-ABCD'],
    'bad label' => ['version' => "1.0\n2"],
    'no label' => ['version' => ''],
];
foreach ($badVersions as $why => $over) {
    [$c, $b] = $swJson('admin', $over + ['package_id' => $pkgA, 'version' => '9.9'] + $msi);
    $ok(in_array($c, [422], true) && (int) $one("SELECT COUNT(*) FROM rmm_package_versions") === 0, "versions: $why is refused and nothing is stored ($c)");
}
[$c, $b] = $swJson('admin', ['package_id' => $pkgA, 'action' => 'add_version', 'fmt' => 'deb', 'version' => '1']);
$ok($c === 422 && str_contains($b, 'Choose the format'), 'versions: an unknown format is a message');
[$c, $b] = $swJson('viewer', ['package_id' => $pkgA] + $msi);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_versions") === 0, 'versions: a view-only role cannot add one (' . $c . ')');
[$c, $b] = $swJson('clientb', ['package_id' => $pkgA] + $msi);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_versions") === 0, 'versions: a technician limited to one client cannot add one (' . $c . ')');
[$c, $b] = $swJson('tech', ['package_id' => $pkgA] + $msi);
$verMsi = (int) $one("SELECT version_id FROM rmm_package_versions WHERE package_id=$pkgA");
$ok($c === 200 && $verMsi > 0 && $one("SELECT sha256 FROM rmm_package_versions WHERE version_id=$verMsi") === $swSha && $one("SELECT product_code FROM rmm_package_versions WHERE version_id=$verMsi") === '{12345678-ABCD-1234-ABCD-1234567890AB}'
    && (int) $one("SELECT size_bytes FROM rmm_package_versions WHERE version_id=$verMsi") === 1048576 && $one("SELECT os FROM rmm_package_versions WHERE version_id=$verMsi") === 'windows', 'versions: a valid MSI is stored (hash lower-cased, product code upper-cased, size an integer, the OS derived from the format) (' . $c . ')');
[$c, $b] = $swJson('tech', ['package_id' => $pkgA] + $msi);
$ok($c === 422 && (int) $one("SELECT COUNT(*) FROM rmm_package_versions") === 1, 'versions: the same version label again is refused: a version is never replaced (' . $c . ')');
// the fields of another format are never sent: an apt version posted with an address and a hash is still just a package name
[$c, $b] = $swJson('tech', ['package_id' => $pkgA, 'action' => 'add_version', 'fmt' => 'apt', 'version' => '1.5', 'pkg_name' => 'htop', 'url' => 'https://evil.example/x.deb', 'sha256' => $swSha, 'size_bytes' => '5', 'silent_args' => '/S', 'product_code' => '{x}', 'pin_version' => '1']);
$verApt = (int) $one("SELECT version_id FROM rmm_package_versions WHERE package_id=$pkgA AND fmt='apt'");
$ok($c === 200 && $verApt > 0 && $one("SELECT url FROM rmm_package_versions WHERE version_id=$verApt") === '' && $one("SELECT sha256 FROM rmm_package_versions WHERE version_id=$verApt") === '' && $one("SELECT silent_args FROM rmm_package_versions WHERE version_id=$verApt") === ''
    && $one("SELECT pkg_name FROM rmm_package_versions WHERE version_id=$verApt") === 'htop' && (int) $one("SELECT pin_version FROM rmm_package_versions WHERE version_id=$verApt") === 1 && $one("SELECT detect_kind FROM rmm_package_versions WHERE version_id=$verApt") === 'package', 'versions: an apt version keeps only its package name; an address, hash or argument typed into the form never reaches the row (' . $c . ')');
foreach (['-oAPT::Foo', 'a;touch /tmp/x', 'Bad Name', '$(id)', 'x y', "pkg\nx", str_repeat('a', 101), ''] as $badPkg) {
    [$c, $b] = $swJson('tech', ['package_id' => $pkgA, 'action' => 'add_version', 'fmt' => 'dnf', 'version' => 'v' . substr(md5($badPkg), 0, 6), 'pkg_name' => $badPkg]);
    $ok($c === 422, 'versions: the package name ' . json_encode(substr($badPkg, 0, 30)) . ' is refused (' . $c . ')');
}
foreach (['/S "quoted"', '/S & calc', '/S; rm', '/S|x', '/S $(x)', '/S  /x', str_repeat('a', 81)] as $badArgs) {
    [$c, $b] = $swJson('tech', ['package_id' => $pkgA, 'action' => 'add_version', 'fmt' => 'exe', 'version' => 'x' . substr(md5($badArgs), 0, 6), 'url' => "https://$swHost/s.exe", 'sha256' => $swSha, 'size_bytes' => '5000', 'silent_args' => $badArgs, 'detect_kind' => 'uninstall_name', 'detect_value' => 'Acme']);
    $ok($c === 422, 'versions: the silent arguments ' . json_encode(substr($badArgs, 0, 20)) . ' are refused (' . $c . ')');
}
[$c, $b] = $swJson('tech', ['package_id' => $pkgA, 'action' => 'add_version', 'fmt' => 'exe', 'version' => '9.1', 'url' => "https://$swHost/s.exe", 'sha256' => $swSha, 'size_bytes' => '5000', 'silent_args' => '/S /norestart', 'detect_kind' => 'uninstall_name', 'detect_value' => 'Acme Agent', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_package_versions WHERE fmt='exe'") === 1 && $one("SELECT product_code FROM rmm_package_versions WHERE fmt='exe'") === '', 'versions: an EXE is stored with its detection rule (a product code typed for an EXE is not sent) (' . $c . ')');
[$c, $body] = $swGet('admin', "/agent/rmm_software_catalog.php?package_id=$pkgA");
$ok(str_contains($body, 'data-rmm-copy="' . $swSha . '"') && str_contains($body, 'app-2.4.1.msi') && str_contains($body, '1048576 bytes') && str_contains($body, 'htop') && str_contains($body, 'Retire'), 'catalog page: a version shows its address, the whole hash to copy, the exact size and a Retire button');
[$c, $body] = $swGet('viewer', "/agent/rmm_software_catalog.php?package_id=$pkgA");
$ok($c === 200 && str_contains($body, $swHost) && !str_contains($body, 'app-2.4.1.msi') && !str_contains($body, 'name="action" value="add_version"') && !str_contains($body, 'Retire'), 'catalog page: a view-only role sees the installer\'s host but not its path, and no forms');
// immutability: only "retired" can change, through the API or the UI
$r = rivetRmmSoftwareApi($ctxA, 'PATCH', ['software_packages', $pkgA, 'versions', $verMsi], [], ['sha256' => str_repeat('c', 64)]);
$ok(!$r['ok'] && $r['status'] === 422 && $one("SELECT sha256 FROM rmm_package_versions WHERE version_id=$verMsi") === $swSha, 'versions: the hash of a saved version cannot be changed (' . $r['status'] . ')');
$r = rivetRmmSoftwareApi($ctxA, 'PATCH', ['software_packages', $pkgA, 'versions', $verMsi], [], ['retired' => true, 'url' => 'https://' . $swHost . '/b.msi']);
$ok(!$r['ok'] && $r['status'] === 422 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$verMsi") === 0, 'versions: retired plus another field is refused as a whole (' . $r['status'] . ')');
[$c, $b] = $swJson('viewer', ['action' => 'retire_version', 'package_id' => $pkgA, 'version_id' => $verApt, 'retired' => '1']);
$ok($c === 403 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$verApt") === 0, 'versions: a view-only role cannot retire one (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'retire_version', 'package_id' => $pkgA, 'version_id' => $verApt, 'retired' => '1']);
$ok($c === 200 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$verApt") === 1, 'versions: a version is retired');
[$c, $b] = $swJson('tech', ['action' => 'retire_version', 'package_id' => $pkgA, 'version_id' => $verApt, 'retired' => '0']);
$ok($c === 200 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$verApt") === 0, 'versions: and restored');

// ---- the two-person flag: raising is open to the catalog manager, lowering needs an administrator
[$c, $b] = $swJson('tech', ['action' => 'save_package', 'package_id' => $pkgA, 'name' => 'Acme Agent', 'publisher' => 'Acme', 'description' => 'Test package', 'enabled' => '1', 'two_person' => '1']);
$ok($c === 200 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pkgA") === 1, 'two people: a catalog manager switches the flag on');
[$c, $b] = $swJson('tech', ['action' => 'save_package', 'package_id' => $pkgA, 'name' => 'Acme Agent', 'publisher' => 'Acme', 'description' => 'Test package', 'enabled' => '1']);
$ok($c === 403 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pkgA") === 1, 'two people: the same person cannot switch it off (' . $c . ')');
[$c, $b] = $swJson('rebootonly', ['action' => 'save_package', 'package_id' => $pkgA, 'name' => 'Acme Agent', 'enabled' => '1']);
$ok($c === 403 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pkgA") === 1, 'two people: editing a package without sending the flag counts as lowering it and is refused too (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'save_package', 'package_id' => $pkgA, 'name' => 'Acme Agent', 'publisher' => 'Acme', 'description' => 'Changed text', 'enabled' => '1', 'two_person' => '1']);
$ok($c === 200 && $one("SELECT description FROM rmm_packages WHERE package_id=$pkgA") === 'Changed text' && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pkgA") === 1, 'two people: other edits keep the flag');
[$c, $body] = $swGet('tech', "/agent/rmm_software_catalog.php?package_id=$pkgA");
$ok(str_contains($body, 'Only an administrator can switch this off'), 'catalog page: it says who can switch the flag off');
[$c, $b] = $swJson('admin', ['action' => 'save_package', 'package_id' => $pkgA, 'name' => 'Acme Agent', 'publisher' => 'Acme', 'description' => 'Test package', 'enabled' => '1']);
$ok($c === 200 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pkgA") === 0, 'two people: an administrator may switch it off');

// ---- assignments
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php');
$ok(str_contains($body, 'Nothing is assigned yet') && str_contains($body, 'pilot') && str_contains($body, 'at once') && str_contains($body, 'after 7 days') && str_contains($body, 'name="action" value="assign"'), 'assignments page: the empty state, the rings with their delay and the form');
[$c, $body] = $swGet('rebootonly', '/agent/rmm_software_assignments.php');
$ok(!str_contains($body, 'name="action" value="assign"') && str_contains($body, 'can see assignments but not change them'), 'assignments page: a role that manages but cannot deploy sees no assign form');
$asg = ['action' => 'assign', 'package_id' => $pkgA, 'assign_action' => 'install', 'sa_type' => 'client', 'sa_id_client' => '1', 'version_id' => '0'];
[$c, $b] = $swJson('viewer', $asg);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign: a view-only role cannot (' . $c . ')');
[$c, $b] = $swJson('rebootonly', $asg);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign: a role without the deploy right cannot (' . $c . ')');
[$c, $b] = $swJson('clientb', $asg);
$ok($c === 404 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign (IDOR): a Client B technician assigning to Client A gets the 404 of a missing client (' . $c . ')');
[$c, $b] = $swJson('clientb', ['sa_type' => 'device', 'sa_id_device' => (string) $swWin] + $asg);
$ok($c === 404 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign (IDOR): nor to a Client A device (' . $c . ')');
[$c, $b] = $swJson('clientb', ['sa_type' => 'global'] + $asg);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign: a technician limited to one client cannot assign to everyone (' . $c . ')');
[$c, $b] = $swJson('admin', ['sa_id_client' => '0'] + $asg);
$ok($c === 422 && str_contains($b, 'Choose where'), 'assign: a target is required');
[$c, $b] = $swJson('admin', ['assign_action' => 'wipe'] + $asg);
$ok($c === 422 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign: an action that is not install or uninstall is refused (' . $c . ')');
[$c, $b] = $swJson('admin', ['version_id' => '99999'] + $asg);
$ok($c === 422 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 0, 'assign: a version that is not of the package is refused (' . $c . ')');
[$c, $b] = $swJson('tech', $asg);
$asgId = (int) $one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id=$pkgA AND scope_type='client' AND scope_id=1");
$ok($c === 200 && $asgId > 0 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgId") === 1, 'assign: a technician with the deploy right assigns a package to a client (' . $c . ')');
[$c, $b] = $swJson('tech', ['assign_action' => 'uninstall'] + $asg);
$ok($c === 409 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments") === 1, 'assign: a conflicting assignment for the same scope is refused (' . $c . ')');
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php');
$ok(str_contains($body, 'Client Client A') && str_contains($body, 'Acme Agent') && str_contains($body, 'Live') && str_contains($body, 'newest that fits'), 'assignments page: the assignment is listed with its scope and state');
[$c, $body] = $swGet('clientb', '/agent/rmm_software_assignments.php');
$ok(!str_contains($body, 'Client Client A') && str_contains($body, 'Nothing is assigned yet'), 'assignments page (IDOR): a Client B technician does not see a Client A assignment');
[$c, $b] = $swJson('viewer', ['action' => 'unassign', 'assignment_id' => $asgId]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments WHERE assignment_id=$asgId") === 1, 'unassign: a view-only role cannot (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'unassign', 'assignment_id' => $asgId]);
$ok($c === 404 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments WHERE assignment_id=$asgId") === 1, 'unassign (IDOR): a Client B technician removing a Client A assignment is a 404 (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'delete_package', 'package_id' => $pkgA]);
$ok($c === 409 && $swPkgId('Acme Agent') === $pkgA, 'packages: an assigned package cannot be deleted (' . $c . ')');

// ---- one device: the Deployment tab, check now, dry run, real run
$q("INSERT INTO rmm_device_state SET device_id=$swWin, platform='windows', capabilities_json='[\"software:detect\",\"software:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json), platform='windows'");
$q("INSERT INTO rmm_device_state SET device_id=$swLnx, platform='linux', capabilities_json='[\"software:detect\",\"software:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json), platform='linux'");
[$c, $body] = $swGet('admin', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok($c === 200 && str_contains($body, 'id="rmm-tab-deploy"') && str_contains($body, 'id="rmm-pane-deploy"') && str_contains($body, 'Live installs are off') && str_contains($body, 'Check now') && str_contains($body, 'name="action" value="dry_run"'), 'asset page: the Deployment tab, the live-installs-off note and the actions');
$ok(substr_count($body, 'id="rmmAutoConfirm"') === 1, 'asset page: the in-page confirmation dialog is present exactly once even with the Patches tab');
[$c, $b] = $swJson('viewer', ['action' => 'detect', 'device_id' => $swWin]);
$ok($c === 403 && $swJobs($swWin, 'software_detect') === 0, 'detect: a view-only role cannot ask a device (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'detect', 'device_id' => $swWin]);
$ok($c === 404 && $swJobs($swWin, 'software_detect') === 0, 'detect (IDOR): a Client B technician asking a Client A device gets the 404 of a missing device (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'detect']);
$ok($c === 422 && str_contains($b, 'Missing device'), 'detect: a device is required');
[$c, $b] = $swJson('rebootonly', ['action' => 'detect', 'device_id' => $swWin]);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) === true && $swJobs($swWin, 'software_detect') === 1, 'detect: a role with manage asks the device what it has: one read-only job (' . $c . ')');
[$c, $b] = $swJson('rebootonly', ['action' => 'detect', 'device_id' => $swWin]);
$ok($c === 200 && $swJobs($swWin, 'software_detect') === 1 && str_contains($b, 'already waiting'), 'detect: a second request while one waits is not queued twice');
// what the device answered, written straight into the table: not installed
$q("UPDATE rmm_package_installs SET installed=0, installed_version='', satisfied=0, detected_at='$swNow' WHERE device_id=$swWin AND package_id=$pkgA");
$ok((int) $one("SELECT COUNT(*) FROM rmm_package_installs WHERE device_id=$swWin AND package_id=$pkgA AND expected_action='install'") === 1, 'detect: asking evaluated the assignment: this device is now expected to have the package');
[$c, $body] = $swGet('admin', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(str_contains($body, 'Missing') && str_contains($body, 'Acme Agent') && str_contains($body, 'Not installed'), 'asset page: the package is listed as missing');
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php');
$ok($c === 200 && str_contains($body, 'Acme Agent') && str_contains($body, 'Compliant') && str_contains($body, '0%'), 'report page: by package, 0 percent compliant');
// dry run: nothing but the catalog row reaches the job
[$c, $b] = $swJson('viewer', ['action' => 'dry_run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install']);
$ok($c === 403 && $swJobs($swWin, 'software_install') === 0, 'dry run: a view-only role cannot (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'dry_run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install']);
$ok($c === 404 && $swJobs($swWin, 'software_install') === 0, 'dry run (IDOR): a Client B technician on a Client A device is a 404 (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'dry_run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'format']);
$ok($c === 422 && $swJobs($swWin, 'software_install') === 0, 'dry run: an action that is not install or uninstall is refused (' . $c . ')');
[$c, $b] = $swJson('rebootonly', ['action' => 'dry_run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install', 'url' => 'https://evil.example/x.exe', 'args' => '/S & calc', 'sha256' => str_repeat('0', 64), 'pkg' => '-oAPT::Foo', 'silent_args' => 'x']);
$job = $swRows("SELECT * FROM endpoint_agent_jobs WHERE device_id=$swWin AND type='software_install' ORDER BY created_at DESC LIMIT 1")[0] ?? [];
$jobText = (string) json_encode($job);
$ok($c === 200 && $job !== [] && (json_decode((string) ($job['params_json'] ?? ''), true)['mode'] ?? '') === 'dry_run' && !str_contains($jobText, 'evil.example') && !str_contains($jobText, 'calc') && !str_contains($jobText, '-oAPT') && str_contains($jobText, $swHost) && str_contains($jobText, $swSha),
    'dry run: queued as a dry run; an address, argument, hash or package name typed into the request never reaches the job, which carries only the catalog row (' . $c . ')');
$q("DELETE FROM endpoint_agent_jobs WHERE device_id=$swWin AND type='software_install'");
$q("DELETE FROM rmm_package_jobs WHERE device_id=$swWin AND type='software_install'");
// real run
[$c, $b] = $swJson('tech', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install']);
$ok($c === 422 && str_contains($b, 'confirmation box') && $swJobs($swWin, 'software_install') === 0, 'run: without the confirmation box nothing is sent to Core');
[$c, $b] = $swJson('rebootonly', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install', 'confirm_run' => '1']);
$ok($c === 403 && $swJobs($swWin, 'software_install') === 0, 'run: a role without the deploy right cannot, even with the box ticked (' . $c . ')');
[$c, $b] = $swJson('clientb', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install', 'confirm_run' => '1']);
$ok($c === 404 && $swJobs($swWin, 'software_install') === 0, 'run (IDOR): a Client B technician on a Client A device is a 404 (' . $c . ')');
$swJson('admin', ['action' => 'save_package', 'name' => 'Windows only', 'enabled' => '1']);
$pkgW = $swPkgId('Windows only');
$swJson('admin', ['action' => 'add_version', 'package_id' => $pkgW, 'fmt' => 'msi', 'version' => '1.0', 'url' => "https://$swHost/w.msi", 'sha256' => $swSha, 'size_bytes' => '4096', 'product_code' => '{BBBBBBBB-BBBB-4BBB-8BBB-BBBBBBBBBBBB}']);
[$c, $b] = $swJson('tech', ['action' => 'run', 'device_id' => $swLnx, 'package_id' => $pkgW, 'run_action' => 'install', 'confirm_run' => '1']);
$ok($c === 422 && $swJobs($swLnx, 'software_install') === 0, 'run: a package with only a Windows version is not queued for a Linux device (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'dry_run', 'device_id' => $swLnx, 'package_id' => 999999, 'run_action' => 'install']);
$ok($c === 422 && $swJobs($swLnx, 'software_install') === 0, 'dry run: a package id that does not exist is refused (' . $c . ')');
[$c, $b] = $swJson('tech', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgA, 'run_action' => 'install', 'confirm_run' => '1']);
$job = $swRows("SELECT * FROM endpoint_agent_jobs WHERE device_id=$swWin AND type='software_install' ORDER BY created_at DESC LIMIT 1")[0] ?? [];
$ok($c === 200 && $job !== [] && (json_decode((string) ($job['params_json'] ?? ''), true)['mode'] ?? '') === 'run', 'run: a technician with the deploy right queues a real install (' . $c . ')');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Software Job Queued'") === 1, 'run: the real install is audited by Core');
// the device answers: fabricate the result of that job as the agent would report it (Core applies it)
$jobId = (string) ($job['job_id'] ?? '');
$q("UPDATE rmm_package_installs SET last_action='install', last_result='hash_mismatch', last_code='hash_mismatch', last_detail=" . "'" . $esc($swHostile) . "', fail_count=2, attempts=2 WHERE device_id=$swWin AND package_id=$pkgA");
$q("INSERT INTO rmm_package_history SET device_id=$swWin, client_id=1, package_id=$pkgA, version_id=$verMsi, action='install', mode='run', result='hash_mismatch', code='hash_mismatch', detail='" . $esc($swHostile) . "', version='2.4.1', at='$swNow', job_id='$jobId', user_id=10");
$q("DELETE FROM endpoint_agent_jobs WHERE device_id=$swWin AND type='software_install'");
[$c, $body] = $swGet('admin', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(str_contains($body, 'Failed') && str_contains($body, 'Hash did not match') && str_contains($body, 'Install history') && !str_contains($body, '<script>alert("sw")') && !str_contains($body, '<img src=x onerror') && str_contains($body, '&lt;script&gt;alert(&quot;sw&quot;)'),
    'asset page: a failed attempt and the history show, and the hostile detail text from the device is escaped');
[$c, $body] = $swGet('viewer', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(str_contains($body, 'id="rmm-tab-deploy"') && !str_contains($body, 'name="package_id"') && !str_contains($body, 'value="dry_run"') && str_contains($body, 'can see software state but not change it'), 'asset page: a view-only role sees the list and no action');
[$c, $body] = $swGet('rebootonly', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(str_contains($body, 'name="action" value="dry_run"') && !str_contains($body, 'name="action" value="run"') && str_contains($body, 'needs a role with the deploy right'), 'asset page: a role that manages but cannot deploy gets a dry run and no Run now button');
[$c, $body] = $swGet('tech', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(str_contains($body, 'name="action" value="run"') && str_contains($body, 'name="confirm_run"'), 'asset page: a role with the deploy right is offered Run now with its own confirmation box');
[$c, $body] = $swGet('stock', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(!str_contains($body, 'id="rmm-tab-deploy"') && !str_contains($body, 'Acme Agent'), 'asset page: a technician with no RMM role rows gets no Deployment tab and no package text');
[$c, $body] = $swGet('clientb', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(!str_contains($body, 'id="rmm-tab-deploy"') && !str_contains($body, 'Acme Agent'), 'asset page (IDOR): a Client B technician opening a Client A asset sees no deployment data');
// the Linux device (Client B) has nothing assigned
[$c, $body] = $swGet('clientb', '/agent/asset_details.php?asset_id=' . $swLnxAsset);
$ok(str_contains($body, 'id="rmm-tab-deploy"') && str_contains($body, 'Nothing is assigned to this device') && !str_contains($body, 'Software expected on this device') && !str_contains($body, 'Install history'), 'asset page: a Client B technician sees the Deployment tab of their own device, with nothing assigned (the catalog is fleet-wide and may be picked from; Client A\'s expectations and history are not shown)');

// ---- two people: a package that needs a second person for an assignment and for a real run
$swJson('admin', ['action' => 'save_package', 'name' => 'Two Person App', 'enabled' => '1', 'two_person' => '1']);
$pkgT = $swPkgId('Two Person App');
$swJson('admin', ['action' => 'add_version', 'package_id' => $pkgT, 'fmt' => 'apt', 'version' => '3.0', 'pkg_name' => 'tpapp']);
$swJson('admin', ['action' => 'add_version', 'package_id' => $pkgT, 'fmt' => 'msi', 'version' => '3.0', 'url' => "https://$swHost/tp.msi", 'sha256' => $swSha, 'size_bytes' => '2048', 'product_code' => '{AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA}']);
[$c, $b] = $swJson('tech', ['action' => 'assign', 'package_id' => $pkgT, 'assign_action' => 'install', 'sa_type' => 'client', 'sa_id_client' => '1']);
$tAsg = (int) $one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id=$pkgT");
$apr = $swRows("SELECT * FROM rmm_approvals WHERE kind='software' ORDER BY approval_id DESC LIMIT 1")[0] ?? null;
$ok($c === 200 && $tAsg > 0 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$tAsg") === 0 && $apr !== null && str_contains($b, 'second person'), 'two people: an assignment of a two-person package is saved switched off and sent for approval (' . $c . ')');
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php');
$ok(str_contains($body, 'Waiting for a second person') && str_contains($body, 'kind=software'), 'two people: the assignments page says it is waiting and links to the queue');
[$c, $body] = $swGet('admin', '/agent/rmm_approvals.php?state=pending_approval&kind=software');
$ok($c === 200 && str_contains($body, 'Software') && str_contains($body, 'Two Person App') && str_contains($body, 'What approving does') && !str_contains($body, 'Script text'), 'two people: the approvals page shows the request as software (no script details)');
$aprId = (int) ($apr['approval_id'] ?? 0);
$decide = fn (string $who, string $decision, int $id) => web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS[$who], ['csrf_token' => 'csrftok1', 'action' => 'decide_approval', 'approval_id' => $id, 'decision' => $decision], ['Accept: application/json']);
[$c, $b] = $decide('tech', 'approve', $aprId);
$ok($c !== 200 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$tAsg") === 0, 'two people: the requester cannot approve their own assignment (' . $c . ')');
[$c, $b] = $decide('viewer', 'approve', $aprId);
$ok($c === 403 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$tAsg") === 0, 'two people: a view-only role cannot approve (' . $c . ')');
[$c, $b] = $decide('clientb', 'approve', $aprId);
$ok($c !== 200 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$tAsg") === 0, 'two people (IDOR): a Client B technician cannot decide a request about Client A (' . $c . ')');
[$c, $b] = $decide('admin', 'approve', $aprId);
$ok($c === 200 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$tAsg") === 1, 'two people: a second person approves and the assignment goes live (' . $c . ')');
// a real run of a two-person package waits too
[$c, $b] = $swJson('tech', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgT, 'run_action' => 'install', 'confirm_run' => '1']);
$j = json_decode($b, true);
$apr2 = $swRows("SELECT * FROM rmm_approvals WHERE kind='software' ORDER BY approval_id DESC LIMIT 1")[0] ?? null;
$ok($c === 200 && $swJobs($swWin, 'software_install') === 0 && $apr2 !== null && (int) $apr2['approval_id'] !== $aprId && str_contains((string) ($j['message'] ?? ''), 'Sent for approval'), 'two people: a real run of a two-person package is sent for approval, not queued (' . $c . ')');
$aprId = (int) $apr2['approval_id'];
[$c, $body] = $swGet('admin', '/agent/rmm_approvals.php?state=pending_approval&kind=software');
$ok(str_contains($body, 'Install now on one device') || str_contains($body, 'now on one device'), 'two people: the approvals page describes a run on one device');
[$c, $b] = $decide('admin', 'reject', $aprId);
$ok($c === 200 && $swJobs($swWin, 'software_install') === 0 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$aprId") === 'rejected', 'two people: rejecting a run queues nothing (' . $c . ')');
// a dry run never needs a second person
[$c, $b] = $swJson('tech', ['action' => 'dry_run', 'device_id' => $swWin, 'package_id' => $pkgT, 'run_action' => 'install']);
$ok($c === 200 && $swJobs($swWin, 'software_install') === 1, 'two people: a dry run of a two-person package is queued at once (' . $c . ')');
$q("DELETE FROM endpoint_agent_jobs WHERE type='software_install'"); $q("DELETE FROM rmm_package_jobs WHERE type='software_install'");
// with the script library off, software requests can still be decided but script requests cannot
$featS = $feat; $featS['scripts'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $featS]);
$fresh();
$swJson('tech', ['action' => 'run', 'device_id' => $swWin, 'package_id' => $pkgT, 'run_action' => 'install', 'confirm_run' => '1']);
$aprId = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='software' AND state='pending_approval' ORDER BY approval_id DESC LIMIT 1");
[$c, $body] = $swGet('admin', '/agent/rmm_approvals.php');
$ok($c === 200 && !str_contains($body, 'switched off') && str_contains($body, 'Two Person App'), 'scripts off: the approvals page still opens, showing the software request (' . $c . ')');
$q("INSERT INTO rmm_approvals SET kind='run', state='pending_approval', summary='SW script run', request_json='{}', request_sha256='" . hash('sha256', '{}') . "', device_count=1, requested_by=10, requested_at='$swNow', expires_at='" . gmdate('Y-m-d H:i:s', time() + 3600) . "'");
$runApr = (int) $db->insert_id;
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'decide_approval', 'approval_id' => $runApr, 'decision' => 'approve'], ['Accept: application/json']);
$ok($c === 403 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$runApr") === 'pending_approval', 'scripts off: a script run cannot be decided through the software fallback (' . $c . ')');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'cancel_approval', 'approval_id' => $runApr], ['Accept: application/json']);
$ok($c === 403 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$runApr") === 'pending_approval', 'scripts off: nor cancelled through it (' . $c . ')');
$q("DELETE FROM rmm_approvals WHERE approval_id=$runApr");
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'decide_approval', 'approval_id' => $aprId, 'decision' => 'reject'], ['Accept: application/json']);
$ok($c === 200 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$aprId") === 'rejected', 'scripts off: the software request can be decided (' . $c . ')');
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();
$swJson('admin', ['action' => 'unassign', 'assignment_id' => $tAsg]);
$q("DELETE FROM rmm_approvals WHERE kind='software'");

// ---- the report and the CSV
$q("DELETE FROM rmm_package_installs WHERE package_id=$pkgT");
// a second device with a formula-looking host name, to prove the CSV is safe to open in a spreadsheet
$q("INSERT INTO rmm_package_installs SET device_id=$swLnx, package_id=$pkgA, client_id=2, expected_action='install', expected_version_id=$verApt, ring='', expected_since='2026-01-01 00:00:00', installed=1, installed_version='1.5', satisfied=1, detected_at='$swNow', updated_at='$swNow'");
$q("UPDATE endpoint_agent_devices SET hostname='=HYPERLINK(\"http://evil.example\")' WHERE device_id=$swLnx");
foreach (['package', 'client', 'ring', 'device'] as $by) {
    [$c, $body] = $swGet('admin', "/agent/rmm_software_report.php?group_by=$by");
    $ok($c === 200 && !str_contains($body, 'Fatal error') && str_contains($body, 'Acme Agent'), "report page: grouped by $by renders for an administrator ($c)");
}
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=device');
$ok(str_contains($body, 'rmm_agent_device.php?device_id=' . $swWin . '#rmm-deploy') && str_contains($body, 'As wanted') && str_contains($body, 'Missing') && str_contains($body, '=HYPERLINK(&quot;http://evil.example&quot;)'), 'report page: the device view lists both states and escapes a hostile host name');
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=client');
$ok(str_contains($body, 'Client A') && str_contains($body, 'Client B') && str_contains($body, '50%'), 'report page: by client names the clients and the percentage');
[$c, $body] = $swGet('clientb', '/agent/rmm_software_report.php?group_by=device');
$ok(!str_contains($body, 'rmm_agent_device.php?device_id=' . $swWin . '#') && str_contains($body, 'rmm_agent_device.php?device_id=' . $swLnx . '#') && !str_contains($body, 'Client A'), 'report page (IDOR): a Client B technician sees only Client B rows');
[$c, $body] = $swGet('clientb', '/agent/rmm_software_report.php?group_by=package&client_id=1');
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Client A') && !str_contains($body, 'rmm_agent_device.php'), 'report page (IDOR): naming another client\'s id shows nothing of it');
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=bogus&client_id=abc&state=evil&ring=' . urlencode("x' OR 1=1 -- ") . '&page=-4');
$ok($c === 200 && !str_contains($body, 'Fatal error'), 'report page: odd filter values are ignored, not an error (' . $c . ')');
[$c, $body, $hd] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=device');
$lines = preg_split('/\r\n/', trim($body));
$ok($c === 200 && stripos($hd, 'content-type: text/csv') !== false && stripos($hd, 'attachment') !== false && stripos($hd, 'nosniff') !== false && ($lines[0] ?? '') === 'device_id,hostname,client_id,package_id,package,ring,expected,expected_version,installed_version,state,last_result', 'csv: a text/csv attachment with the header (' . $c . ')');
$ok(str_contains($body, "'=HYPERLINK") && !preg_match('/^\d+,=HYPERLINK/m', $body), 'csv: a host name that starts with = gets a leading apostrophe, so a spreadsheet never runs it');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Software Report Exported'") >= 1, 'csv: the export is audited by Core');
[$c, $body] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=package');
$ok($c === 200 && str_contains($body, 'Acme Agent') && str_starts_with($body, 'key,label,expected,'), 'csv: the package grouping');
[$c, $body] = $swGet('clientb', '/agent/rmm_software_report_csv.php?group_by=device');
$ok($c === 200 && substr_count($body, "\r\n") === 2 && preg_match('/^' . $swWin . ',/m', $body) === 0 && preg_match('/^' . $swLnx . ',/m', $body) === 1, 'csv (IDOR): a Client B technician gets only Client B rows');
[$c, $body] = $swGet('clientb', '/agent/rmm_software_report_csv.php?group_by=device&client_id=1');
$ok($c === 404 && !str_contains($body, 'Acme'), 'csv (IDOR): asking for another client is the 404 of a missing one (' . $c . ')');
[$c, $body] = $swGet('stock', '/agent/rmm_software_report_csv.php');
$ok($c === 403, 'csv: a technician with no RMM role rows is refused (' . $c . ')');
[$c, $body] = $swGet('viewer', '/agent/rmm_software_report_csv.php?group_by=ring&client_id=zzz&limit=99999');
$ok($c === 200 && !str_contains($body, 'Fatal error'), 'csv: a viewer may download; junk parameters are ignored (' . $c . ')');
$q("UPDATE endpoint_agent_devices SET hostname='lnx1' WHERE device_id=$swLnx AND hostname LIKE '=%'");

// ---- the administration card
$pg = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$adm = fn (array $f, string $who = 'admin') => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $REF1);
[$c, $body] = $pg();
$ok($c === 200 && str_contains($body, 'id="software-deployment"') && str_contains($body, 'name="feature_software_deploy"') && str_contains($body, 'name="software_live_confirm"') && str_contains($body, 'has not been run on a real machine'), 'admin card: Software deployment with the switch, the limits, live installs and its acknowledgement, and the Windows warning');
[$c, $body] = $pg('tech');
$ok(!str_contains($body, 'id="software-deployment"') && !str_contains($body, 'name="save_software_settings"'), 'admin card: a technician is not shown it (' . $c . ')');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1'], 'tech');
$fresh();
$limits = fn () => (array) ($rmm->readModel()->settingsSummary()['limits'] ?? []);
$ok($rmm->featureOn('software_deploy') && (int) ($limits()['software_live_install'] ?? 0) === 0, 'admin card: a technician\'s post changes nothing (the switch stays as it was, on from above; live stays off)');
$adm(['save_software_settings' => 1, 'csrf_token' => 'wrong', 'software_live_install' => '1', 'software_live_confirm' => '1']);
$fresh();
$ok((int) ($limits()['software_live_install'] ?? 0) === 0, 'admin card: a wrong CSRF token changes nothing');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1', 'software_live_install' => '1']);
$fresh();
$ok((int) ($limits()['software_live_install'] ?? 0) === 0, 'admin card: live installs without the acknowledgement are refused');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1', 'software_detect_hours' => '6', 'software_max_installs' => '10', 'software_max_installs_per_client' => '2', 'software_deploy_history_days' => '90']);
$fresh();
$l = $limits();
$ok((int) $l['software_detect_interval_s'] === 21600 && (int) $l['software_max_installs'] === 10 && (int) $l['software_max_installs_per_client'] === 2 && (int) $l['software_deploy_history_days'] === 90 && (int) ($l['software_live_install'] ?? 0) === 0, 'admin card: the limits are saved (hours become seconds) and live installs stay off');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1', 'software_detect_hours' => '99999', 'software_max_installs' => '0', 'software_max_installs_per_client' => 'many', 'software_deploy_history_days' => '1']);
$fresh();
$l = $limits();
$ok((int) $l['software_detect_interval_s'] === 168 * 3600 && (int) $l['software_max_installs'] === 1 && (int) $l['software_max_installs_per_client'] === 2 && (int) $l['software_deploy_history_days'] === 30, 'admin card: out-of-range numbers are clamped and text is ignored');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1', 'software_live_install' => '1', 'software_live_confirm' => '1']);
$fresh();
$ok((int) ($limits()['software_live_install'] ?? 0) === 1, 'admin card: live installs are switched on with the acknowledgement');
[$c, $body] = $swGet('admin', '/agent/asset_details.php?asset_id=' . $swWinAsset);
$ok(!str_contains($body, 'Live installs are off'), 'asset page: with live installs on, the note is gone');
$ok(str_contains((string) $one("SELECT log_description FROM logs WHERE log_type='RMM' AND log_description LIKE '%software deployment settings%' ORDER BY log_id DESC LIMIT 1"), 'live installs on'), 'admin card: the change is written to the audit log');
$adm(['save_software_settings' => 1, 'feature_software_deploy' => '1']);
$fresh();
$ok((int) ($limits()['software_live_install'] ?? 0) === 0 && $rmm->featureOn('software_deploy'), 'admin card: leaving the live box unticked switches live installs off again, no acknowledgement needed');

// ---- a database that has not had the migration yet: a notice, not a crash
$q("RENAME TABLE rmm_package_installs TO rmm_package_installs_hold");
foreach (array_merge($swPages, ['/agent/asset_details.php?asset_id=' . $swWinAsset]) as $path) {
    [$c, $body] = $swGet('admin', $path);
    $ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Stack trace'), "schema pending: $path still renders ($c)");
}
[$c, $body] = $swGet('admin', '/agent/rmm_software_report_csv.php');
$ok(in_array($c, [403, 404, 503], true) && !str_contains($body, 'Fatal error'), 'schema pending: the CSV answers with a status, not an error page (' . $c . ')');
$q("RENAME TABLE rmm_package_installs_hold TO rmm_package_installs");

// ---- the delete paths, then the switch off again
[$c, $b] = $swJson('viewer', ['action' => 'remove_host', 'host' => $swHost]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 1, 'hosts: a view-only role cannot remove a host (' . $c . ')');
[$c, $b] = $swJson('admin', ['action' => 'unassign', 'assignment_id' => $asgId]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_package_assignments WHERE assignment_id=$asgId") === 0 && (int) $one("SELECT COUNT(*) FROM rmm_package_versions WHERE package_id=$pkgA") === 3, 'unassign: an assignment is removed and nothing else (no version is touched)');
[$c, $b] = $swJson('admin', ['action' => 'delete_package', 'package_id' => $pkgH]);
$ok($c === 200 && $swPkgId($swHostile) === 0, 'packages: an unassigned package is deleted');
[$c, $b] = $swJson('admin', ['action' => 'remove_host', 'host' => $swHost]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_software_hosts") === 0, 'hosts: an administrator removes a host');
// browser form: a flash and a redirect back; an off-site return address is ignored
[$c, , $hd] = $swPost('admin', ['action' => 'save_package', 'name' => 'Redirect pkg', 'return' => '/agent/rmm_software_catalog.php']);
$ok($c === 302 && str_contains($swLoc($hd), '/agent/rmm_software_catalog.php?package_id=') && $swPkgId('Redirect pkg') > 0, 'handler: a browser form redirects to the new package');
[$c, , $hd] = $swPost('admin', ['action' => 'unassign', 'assignment_id' => '99999', 'return' => 'https://evil.example/x']);
$ok($c === 302 && !str_contains($swLoc($hd), 'evil.example'), 'handler: an off-site return address is ignored (open redirect)');
[$c, , $hd] = $swPost('admin', ['action' => 'unassign', 'assignment_id' => '99999', 'return' => '//evil.example/x']);
$ok($c === 302 && !str_contains($swLoc($hd), 'evil.example'), 'handler: a protocol-relative return address is ignored');
$swWipe();
$feat['software_deploy'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$cur = $rmm->readModel()->settingsSummary();
$st = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? (array) json_decode($cur['limits_json'], true) : [];
foreach (array_keys($st) as $k) { if (str_starts_with((string) $k, 'software_')) { unset($st[$k]); } }
$rmm->admin()->saveSettings($admin, ['limits_json' => $st]);
$fresh();
$ok(!$rmm->featureOn('software_deploy') && $swNav() === [], 'software: switched off again, the menu entries are gone');
[$c, $body] = $swGet('admin', '/agent/rmm_software_catalog.php');
$ok($c === 403 && str_contains($body, 'switched off'), 'software off again: the pages are the switched-off notice');
