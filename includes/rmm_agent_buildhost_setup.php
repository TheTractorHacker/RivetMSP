<?php

/*
 * "RMM Agent Builder" installer support (26.10.34): the logic behind scripts/build_host_setup.php, which deploy/setup-build-host.sh runs as the web server user to create the build host
 * in this application (through LocalBuild::addHost / rotate, the same code as the "Add a build host" button) and to read the site's public URL.
 *
 * Output contract (this is what the shell script relies on):
 *   info   --name N   key=value lines on stdout: ready=0|1, host=none|exists|disabled, site_url=https://...   (never a secret)
 *   create --name N [--rotate]
 *                     the new token and NOTHING else on stdout (one line); every message goes to stderr. It is never written to a log row, the audit log or a file here.
 *   delete --name N   removes the host from the application (its token stops working at once)
 * Exit codes: 0 ok, 2 the database update has not run, 3 the host exists (create without --rotate) , 4 bad input, 5 failed.
 */

namespace RivetMsp\AgentFeed;

/**
 * @param list<string> $args the arguments after the script name
 * @return array{code:int,out:string,err:string}
 */
function rivetBuildHostSetupRun(LocalBuild $lb, string $siteUrl, array $args): array
{
    $r = static fn (int $code, string $out = '', string $err = ''): array => ['code' => $code, 'out' => $out, 'err' => $err];
    $cmd = $args[0] ?? '';
    $name = '';
    $rotate = false;
    for ($i = 1; $i < count($args); $i++) {
        if ($args[$i] === '--name' && isset($args[$i + 1])) {
            $name = $args[++$i];
        } elseif (str_starts_with($args[$i], '--name=')) {
            $name = substr($args[$i], 7);
        } elseif ($args[$i] === '--rotate') {
            $rotate = true;
        } else {
            return $r(4, '', 'unknown argument');
        }
    }
    if (!in_array($cmd, ['info', 'create', 'delete'], true)) {
        return $r(4, '', "usage: build_host_setup.php info|create|delete --name NAME [--rotate]\n");
    }
    if (preg_match(LocalBuild::HOST_NAME_RE, $name) !== 1) {
        return $r(4, '', "the build host name must be a short name (letters, numbers, spaces, dots, dashes)\n");
    }
    if (!$lb->ready()) {
        return $r(2, $cmd === 'info' ? "ready=0\nhost=none\nsite_url=\n" : '', "the database update has not run (Administration > Updates), so build hosts are not available yet\n");
    }
    $found = null;
    foreach ($lb->hosts() as $h) {
        if ((string) $h['host_name'] === $name) {
            $found = $h;
        }
    }
    if ($cmd === 'info') {
        $state = $found === null ? 'none' : ((int) $found['enabled'] === 1 ? 'exists' : 'disabled');
        $url = preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~/-]*)?\z#', $siteUrl) === 1 ? rtrim($siteUrl, '/') : '';

        return $r(0, "ready=1\nhost=$state\nsite_url=$url\n");
    }
    if ($cmd === 'delete') {
        if ($found === null) {
            return $r(0, '', "no build host named \"$name\" (nothing to remove)\n");
        }
        $d = $lb->deleteHost((int) $found['host_id']);

        return $d['ok'] ? $r(0, '', $d['message'] . "\n") : $r(5, '', $d['message'] . "\n");
    }
    // create
    if ($found !== null) {
        if (!$rotate) {
            return $r(3, '', "a build host named \"$name\" already exists; its token cannot be shown again (use --rotate to replace it)\n");
        }
        $x = $lb->rotate((int) $found['host_id']);
        if ((int) $found['enabled'] !== 1) {
            $lb->setEnabled((int) $found['host_id'], true);
        }
    } else {
        $x = $lb->addHost($name, 0);
    }
    if (!$x['ok'] || !isset($x['token']) || preg_match(LocalBuild::TOKEN_RE, $x['token']) !== 1) {
        return $r(5, '', ($x['message'] ?? 'the build host could not be created') . "\n");
    }

    return $r(0, $x['token'] . "\n", $x['message'] . "\n");
}
