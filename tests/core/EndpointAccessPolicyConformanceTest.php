<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Testing\AccessPolicyConformanceTestCase;

/**
 * EndpointAccessPolicy: the eleven rmm.* abilities over RivetMSP's role model (user_role_permissions per module, user_roles.role_is_admin). Roles: an
 * administrator, a full technician (rmm 3, scripts 3, remote 1), a viewer (rmm 1), a reboot-only technician (scripts 2), and a stock technician
 * with NO rmm module rows (a fresh install has none, so only administrators hold RMM until an admin grants them). Client-portal contacts
 * (user_type 2), disabled and archived accounts hold nothing, whatever role row they carry.
 */
final class EndpointAccessPolicyConformanceTest extends AccessPolicyConformanceTestCase
{
    private const USERS = ['admin' => 940001, 'tech' => 940002, 'viewer' => 940003, 'reboot' => 940004, 'stock' => 940005, 'disabled' => 940006, 'portal' => 940007, 'archived' => 940008];

    protected function policy(): AccessPolicyInterface
    {
        $db = EndpointKit::db();
        foreach (['module_rmm' => 940101, 'module_rmm_scripts' => 940102, 'module_rmm_remote_connect' => 940103, 'module_support' => 940104] as $name => $id) {
            $db->execute('INSERT INTO modules SET module_id = ?, module_name = ? ON DUPLICATE KEY UPDATE module_name = VALUES(module_name)', [$id, $name]);
        }
        // role id => [name, admin, [module id => level]]
        $roles = [940201 => ['Admin', 1, []], 940202 => ['Tech', 0, [940101 => 3, 940102 => 3, 940103 => 1, 940104 => 3]], 940203 => ['Viewer', 0, [940101 => 1, 940104 => 3]],
            940204 => ['RebootOnly', 0, [940101 => 1, 940102 => 2, 940104 => 3]], 940205 => ['Stock', 0, [940104 => 3]]];
        foreach ($roles as $rid => [$name, $adm, $levels]) {
            $db->execute('INSERT INTO user_roles SET role_id = ?, role_name = ?, role_is_admin = ?, role_type = 1 ON DUPLICATE KEY UPDATE role_is_admin = VALUES(role_is_admin)', [$rid, 'conf-' . $name, $adm]);
            $db->execute('DELETE FROM user_role_permissions WHERE user_role_id = ?', [$rid]);
            foreach ($levels as $mid => $lvl) {
                $db->execute('INSERT INTO user_role_permissions SET user_role_id = ?, module_id = ?, user_role_permission_level = ?', [$rid, $mid, $lvl]);
            }
        }
        // key => [role, status, type, archived]
        $users = ['admin' => [940201, 1, 1, null], 'tech' => [940202, 1, 1, null], 'viewer' => [940203, 1, 1, null], 'reboot' => [940204, 1, 1, null], 'stock' => [940205, 1, 1, null],
            'disabled' => [940202, 0, 1, null], 'portal' => [940202, 1, 2, null], 'archived' => [940202, 1, 1, '2026-01-01 00:00:00']];
        foreach ($users as $key => [$rid, $status, $type, $archived]) {
            $db->execute("INSERT INTO users SET user_id = ?, user_name = ?, user_email = ?, user_password = 'x', user_type = ?, user_status = ?, user_role_id = ?, user_archived_at = ?
                ON DUPLICATE KEY UPDATE user_status = VALUES(user_status), user_role_id = VALUES(user_role_id), user_archived_at = VALUES(user_archived_at), user_type = VALUES(user_type)",
                [self::USERS[$key], "conf-$key", "conf-$key@example.test", $type, $status, $rid, $archived]);
        }

        return new \RivetMSP\Core\Adapter\Endpoint\EndpointAccessPolicy($db);
    }

    protected function declaresDenyByDefault(): bool
    {
        return true;
    }

    protected function knownAllowed(): array
    {
        $u = self::USERS;

        return [
            [$u['admin'], RmmAbility::ADMIN, 'client', 0], [$u['admin'], RmmAbility::BINARY_PUBLISH, 'client', 3], [$u['admin'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['admin'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['tech'], RmmAbility::DEVICE_VIEW, 'client', 3], [$u['tech'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['tech'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['tech'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['viewer'], RmmAbility::DEVICE_VIEW, 'client', 3], [$u['reboot'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['reboot'], RmmAbility::JOB_RUN_SAVED, 'client', 3],
            // RMM Phase 2 and 3: administrators approve and manage alerts; a technician with RMM write manages alerts
            [$u['admin'], RmmAbility::JOB_APPROVE, 'client', 3], [$u['admin'], RmmAbility::ALERT_MANAGE, 'client', 3], [$u['tech'], RmmAbility::ALERT_MANAGE, 'client', 3],
        ];
    }

    protected function knownDenied(): array
    {
        $u = self::USERS;
        $all = RmmAbility::all();
        $denied = [
            [$u['tech'], RmmAbility::ADMIN, 'client', 0], [$u['tech'], RmmAbility::DEVICE_MANAGE, 'client', 3], [$u['tech'], RmmAbility::TOKEN_MANAGE, 'client', 3], [$u['tech'], RmmAbility::BINARY_PUBLISH, 'client', 3],
            [$u['viewer'], RmmAbility::JOB_RUN_SAVED, 'client', 3], [$u['viewer'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['viewer'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['reboot'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['reboot'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [null, RmmAbility::DEVICE_VIEW, 'client', 3], [999999, RmmAbility::DEVICE_VIEW, 'client', 3],
            // approval is a second person's grant: a level 3 script technician does NOT have it by default; alert management needs RMM write (module_rmm >= 2)
            [$u['tech'], RmmAbility::JOB_APPROVE, 'client', 3], [$u['viewer'], RmmAbility::ALERT_MANAGE, 'client', 3], [$u['reboot'], RmmAbility::ALERT_MANAGE, 'client', 3],
            [$u['disabled'], RmmAbility::ALERT_MANAGE, 'client', 3], [$u['portal'], RmmAbility::JOB_APPROVE, 'client', 3],
        ];
        // The stock technician (no rmm module rows), a client-portal contact, a disabled and an archived account: nothing at all.
        foreach (['stock', 'portal', 'disabled', 'archived'] as $who) {
            foreach ($all as $ability) {
                $denied[] = [$u[$who], $ability, 'client', 3];
            }
        }

        return $denied;
    }

    public function testLevelThreeScriptRolesApproveOnlyWhileTheAdminSettingIsOn(): void
    {
        $policy = $this->policy();
        $db = EndpointKit::db();
        $tech = self::USERS['tech'];
        $db->execute('UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1');
        $this->assertFalse($policy->can($tech, RmmAbility::JOB_APPROVE, 'client', 3), 'off by default');
        $db->execute('UPDATE settings SET config_rmm_approve_scripts_lvl3 = 1 WHERE company_id = 1');
        $this->assertTrue($policy->can($tech, RmmAbility::JOB_APPROVE, 'client', 3), 'on: a level 3 script role may approve');
        $this->assertFalse($policy->can(self::USERS['reboot'], RmmAbility::JOB_APPROVE, 'client', 3), 'on: level 2 still may not');
        $this->assertFalse($policy->can(self::USERS['stock'], RmmAbility::JOB_APPROVE, 'client', 3), 'on: a technician with no RMM rows still may not');
        $this->assertFalse($policy->can(self::USERS['portal'], RmmAbility::JOB_APPROVE, 'client', 3), 'on: a client-portal contact still may not');
        $this->assertTrue($policy->can(self::USERS['admin'], RmmAbility::JOB_APPROVE, 'client', 3));
        $db->execute('UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1');
        $this->assertFalse($policy->can($tech, RmmAbility::JOB_APPROVE, 'client', 3));
    }
}
