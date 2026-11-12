<?php

declare(strict_types=1);

/*
 * Administration > Update > Update App, the safe path (rivet-core#39). The page never runs git as the web user against files another user owns:
 *   'helper'      deploy/install-web-update.sh was run; the page runs the root-owned helper through its one sudo rule
 *   'legacy'      no helper, but the web user owns the checkout, so the old in-page git update cannot leave a mixed-ownership tree
 *   'unsupported' no helper and the files belong to someone else: refuse and say how to set the helper up
 * The server-side check is the control; this file only chooses which path to take.
 */

require_once __DIR__ . '/update_checks.php';

const UPDATE_HELPER_PATH = '/usr/local/lib/rivetmsp-deploy/web-update.sh';

function updateHelperInstalled(): bool
{
    return is_file(UPDATE_HELPER_PATH) && is_file('/etc/rivetmsp/web-update.conf');
}

function updateHelperMode(string $dir): string
{
    if (updateHelperInstalled()) {
        return 'helper';
    }
    $cli = rtrim($dir, '/') . '/scripts/update_cli.php';
    $owner = is_file($cli) ? fileowner($cli) : false;
    $me = function_exists('posix_geteuid') ? posix_geteuid() : null;

    return $owner !== false && $me !== null && $owner === $me ? 'legacy' : 'unsupported';
}

/**
 * Run the helper through sudo (no password prompt: -n). $branch is passed as the helper's own validated option.
 *
 * @return array{ok:bool,code:int,tail:string}
 */
function updateHelperRun(string $branch): array
{
    $cmd = 'sudo -n ' . escapeshellarg(UPDATE_HELPER_PATH)
        . (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,60}$/', $branch) === 1 && !str_contains($branch, '..') ? ' ' . escapeshellarg('--branch=' . $branch) : '')
        . ' 2>&1';
    $out = [];
    $code = 1;
    @set_time_limit(900);
    exec($cmd, $out, $code);
    $text = trim(implode("\n", array_slice($out, -12)));
    // never echo anything that looks like a secret back to the page
    $text = (string) preg_replace('/(pass(word|phrase)?|token|secret|key)\s*[=:]\s*\S+/i', '$1=[hidden]', $text);

    return ['ok' => $code === 0, 'code' => $code, 'tail' => substr($text, -1500)];
}
