<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Authz\RmmAbility;

/**
 * Maps the eleven rmm.* abilities onto RivetMSP's role model: the permission level of a role for a module (user_role_permissions joined to
 * modules, the lookup lookupUserPermission() does for the signed-in user, here keyed by user id) and the administrator flag
 * (user_roles.role_is_admin). Same matrix as RivetIT minus module-only logins, which RivetMSP does not have:
 *
 *   rmm.device.view                          module_rmm >= 1
 *   rmm.job.run_saved, rmm.job.reboot        view + module_rmm_scripts >= 2
 *   rmm.job.run_script                       view + module_rmm_scripts >= 3
 *   rmm.remote.launch                        view + module_rmm_remote_connect >= 1
 *   rmm.device.manage, rmm.token.manage, rmm.binary.publish, rmm.admin    role_is_admin
 *
 *   rmm.job.approve (RMM Phase 2)            role_is_admin; plus a role with module_rmm_scripts >= 3 ONLY while the administrator has switched on
 *                                            "RMM scripts level 3 users may approve" (settings.config_rmm_approve_scripts_lvl3, off by default: the point
 *                                            of an approval is a second person, so it is not handed to everyone who may run scripts).
 *   rmm.alert.manage (RMM Phase 3)           role_is_admin, or module_rmm >= 2 (write): acknowledge/resolve alerts, maintenance windows of one client or
 *                                            device, a device's parent. A role with module_rmm 1 keeps view only.
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
            RmmAbility::ALERT_MANAGE => $this->level($roleId, 'module_rmm') >= 2,
            RmmAbility::JOB_APPROVE => $this->level($roleId, 'module_rmm_scripts') >= 3 && $this->level3MayApprove(),
            default => false,
        };
    }

    /** The administrator's switch for level 3 script users (Administration > Endpoint agent > Approvals). A missing column (code newer than the schema) means off. */
    private function level3MayApprove(): bool
    {
        $r = $this->database->fetchOne('SELECT config_rmm_approve_scripts_lvl3 AS v FROM settings WHERE company_id = 1');

        return $r !== null && (int) $r['v'] === 1;
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
