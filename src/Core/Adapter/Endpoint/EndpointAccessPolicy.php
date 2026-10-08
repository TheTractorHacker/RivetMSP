<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Authz\RmmAbility;

/**
 * Maps the nine rmm.* abilities onto RivetMSP's role model: the permission level of a role for a module (user_role_permissions joined to
 * modules, the lookup lookupUserPermission() does for the signed-in user, here keyed by user id) and the administrator flag
 * (user_roles.role_is_admin). Same matrix as RivetIT minus module-only logins, which RivetMSP does not have:
 *
 *   rmm.device.view                          module_rmm >= 1
 *   rmm.job.run_saved, rmm.job.reboot        view + module_rmm_scripts >= 2
 *   rmm.job.run_script                       view + module_rmm_scripts >= 3
 *   rmm.remote.launch                        view + module_rmm_remote_connect >= 1
 *   rmm.device.manage, rmm.token.manage, rmm.binary.publish, rmm.admin    role_is_admin
 *
 * An administrator holds every module at full access (as lookupUserPermission() does). Only active agents (staff, users.user_type 1) hold
 * anything: a client-portal contact, a disabled or an archived account is denied every ability, whatever role row it carries. The client
 * scope (user_client_permissions) is the tenancy adapter's job. An ability the policy does not know is denied.
 */
final class EndpointAccessPolicy implements AccessPolicyInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        try {
            return $this->decide($userId, $ability);
        } catch (\Throwable) {
            return false;
        }
    }

    private function decide(?int $userId, string $ability): bool
    {
        if ($userId === null || $userId <= 0 || !in_array($ability, RmmAbility::all(), true)) {
            return false;
        }
        $u = $this->database->fetchOne(
            'SELECT u.user_status, u.user_archived_at, u.user_type, u.user_role_id, r.role_is_admin
             FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ?',
            [$userId]
        );
        if ($u === null || (int) $u['user_status'] !== 1 || $u['user_archived_at'] !== null || (int) $u['user_type'] !== 1) {
            return false;
        }
        $admin = (int) ($u['role_is_admin'] ?? 0) === 1;
        if (RmmAbility::isAdministrative($ability)) {
            return $admin;
        }
        if ($admin) {
            return true;
        }
        $roleId = (int) $u['user_role_id'];
        if ($roleId <= 0) {
            return false;
        }
        if ($this->level($roleId, 'module_rmm') < 1) {
            return false;
        }

        return match ($ability) {
            RmmAbility::DEVICE_VIEW => true,
            RmmAbility::JOB_RUN_SCRIPT => $this->level($roleId, 'module_rmm_scripts') >= 3,
            RmmAbility::JOB_RUN_SAVED, RmmAbility::JOB_REBOOT => $this->level($roleId, 'module_rmm_scripts') >= 2,
            RmmAbility::REMOTE_LAUNCH => $this->level($roleId, 'module_rmm_remote_connect') >= 1,
            default => false,
        };
    }

    private function level(int $roleId, string $module): int
    {
        $row = $this->database->fetchOne(
            'SELECT p.user_role_permission_level AS lvl FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id
             WHERE p.user_role_id = ? AND m.module_name = ?',
            [$roleId, $module]
        );

        return $row === null ? 0 : (int) $row['lvl'];
    }
}
