<?php

namespace RivetMSP\Cron;

/**
 * Thin edition subclass of RivetCore\Cron\JobRunner that keeps RivetMSP's own default state directory
 * (/tmp/rivetmsp-jobs-<hash of the app root>) so it never shares PID/log files with another install on the same host. The static
 * helpers (logPathFromCommand, argumentsFromCommand, phpBinaryFromCommand, tail) are inherited.
 */
final class JobRunner extends \RivetCore\Cron\JobRunner
{
    public function __construct(string $appRoot, ?string $stateDir = null)
    {
        parent::__construct($appRoot, $stateDir ?? (sys_get_temp_dir() . '/rivetmsp-jobs-' . substr(md5(realpath($appRoot) ?: $appRoot), 0, 10)));
    }
}
