<?php
declare(strict_types=1);

/**
 * TEST-SEC-05 夹具：受限角色可 VIEW 配置中心 Global Scope，可 UPDATE；支持撤权。
 *
 * stdin JSON:
 *   { "action": "prepare"|"revoke"|"cleanup"|"read_probe"|"restore_probe",
 *     "token"?: string, "role_id"?: int, "user_id"?: int, "grant_id"?: int,
 *     "value"?: mixed }
 * stdout JSON only.
 */

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const SEC05_SOURCES = [
    'Weline_Backend::dashboard',
    'Weline_Backend::system_management',
    'Weline_Backend::system_config_group',
    'Weline_SystemConfig::config_center',
    'Weline_SystemConfig::config_center_index',
    'Weline_SystemConfig::config_center_save',
];
const SEC05_ROLE_PREFIX = 'e2e_sec05_grant_';
const SEC05_USER_PREFIX = 'e2e_sec05_u_';
const SEC05_PROBE_MODULE = 'Weline_Framework';
const SEC05_PROBE_AREA = 'backend';
const SEC05_PROBE_KEY = 'test.ui_enabled';
const SEC05_GRANT_VERSION = 17;

function sec05_require_isolated_postgresql_clone(): string
{
    if ((string)\getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new \RuntimeException('SEC-05 fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $type = \strtolower((string)($env['db']['master']['type'] ?? ''));
    $database = (string)($env['db']['master']['database'] ?? '');
    if ($type !== 'pgsql') {
        throw new \RuntimeException('SEC-05 fixture requires PostgreSQL');
    }
    if (\preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new \RuntimeException('SEC-05 fixture refuses non-clone database');
    }

    return $database;
}

/**
 * @return array<string, mixed>
 */
function sec05_read_input(): array
{
    $raw = \file_get_contents('php://stdin');
    if ($raw === false || \trim($raw) === '') {
        throw new \InvalidArgumentException('empty stdin');
    }
    $data = \json_decode($raw, true);
    if (!\is_array($data)) {
        throw new \InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function sec05_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function sec05_fail(string $message, int $code = 1): never
{
    sec05_output(['ok' => false, 'error' => $message]);
    exit($code);
}

function sec05_protect_ids(int $roleId, int $userId): void
{
    if ($roleId <= 1 || $userId <= 1) {
        throw new \RuntimeException('refusing to mutate protected role/user id <= 1');
    }
}

function sec05_clear_acl_cache(): void
{
    try {
        w_cache('acl')->clear();
    } catch (\Throwable) {
        // best-effort
    }
}

/**
 * @return array{value:mixed,scope:string}
 */
function sec05_read_probe(): array
{
    $om = ObjectManager::getInstance();
    /** @var SystemConfig $config */
    $config = $om->get(SystemConfig::class);
    $value = $config->getConfig(
        SEC05_PROBE_KEY,
        SEC05_PROBE_MODULE,
        SEC05_PROBE_AREA,
        '0',
        SystemConfig::SCOPE_GLOBAL,
    );

    return [
        'value' => $value,
        'scope' => SystemConfig::SCOPE_GLOBAL,
        'module' => SEC05_PROBE_MODULE,
        'area' => SEC05_PROBE_AREA,
        'key' => SEC05_PROBE_KEY,
    ];
}

/**
 * @return array{value:mixed,restored:bool}
 */
function sec05_restore_probe(mixed $value): array
{
    $om = ObjectManager::getInstance();
    /** @var SystemConfig $config */
    $config = $om->get(SystemConfig::class);
    $ok = $config->setScopedConfig(
        SEC05_PROBE_KEY,
        $value,
        SEC05_PROBE_MODULE,
        SEC05_PROBE_AREA,
        SystemConfig::SCOPE_GLOBAL,
        SystemConfig::LOCALE_DEFAULT,
        ['actor' => 'e2e_sec05_fixture'],
    );
    if (!$ok) {
        throw new \RuntimeException('failed to restore probe config');
    }

    return [
        'value' => $value,
        'restored' => true,
        'module' => SEC05_PROBE_MODULE,
        'area' => SEC05_PROBE_AREA,
        'key' => SEC05_PROBE_KEY,
    ];
}

/**
 * @return array<string, mixed>
 */
function sec05_prepare(?string $token): array
{
    $token = $token !== null && $token !== ''
        ? \preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? ''
        : '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(4)) . '_' . (string)\getmypid();
    }
    $roleName = SEC05_ROLE_PREFIX . $token;
    $username = SEC05_USER_PREFIX . $token;
    $email = $username . '@example.test';
    $password = 'Sec05!' . \bin2hex(\random_bytes(6));

    $om = ObjectManager::getInstance();

    /** @var Role $role */
    $role = clone $om->get(Role::class);
    $role->clear()->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
    if ((int)$role->getId() > 0) {
        sec05_cleanup((int)$role->getId(), 0);
        $role = clone $om->get(Role::class);
    }
    $role->clear()
        ->setRoleName($roleName)
        ->setRoleDescription('E2E SEC-05 grant then revoke (auto)')
        ->save(true);
    $roleId = (int)$role->getId();
    if ($roleId <= 1) {
        throw new \RuntimeException('failed to create sec05 role');
    }

    /** @var RoleAccess $access */
    $access = clone $om->get(RoleAccess::class);
    $rows = [];
    foreach (SEC05_SOURCES as $sourceId) {
        $rows[] = [
            RoleAccess::schema_fields_ROLE_ID => $roleId,
            RoleAccess::schema_fields_SOURCE_ID => $sourceId,
        ];
    }
    $access->reset()->insert($rows, [
        RoleAccess::schema_fields_ROLE_ID,
        RoleAccess::schema_fields_SOURCE_ID,
    ])->fetch();

    /** @var ObjectScopeGrant $grantModel */
    $grantModel = clone $om->get(ObjectScopeGrant::class);
    $grantModel->clear()
        ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
        ->delete()
        ->fetch();

    $grantModel->clear()->setData([
        ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
        ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
        ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_GLOBAL,
        ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
        ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
        ObjectScopeGrant::schema_fields_STORE_CODE => null,
        ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
        ObjectScopeGrant::schema_fields_ACTIONS => \json_encode(
            [ObjectAction::VIEW, ObjectAction::UPDATE],
            JSON_UNESCAPED_UNICODE,
        ),
        ObjectScopeGrant::schema_fields_GRANT_VERSION => SEC05_GRANT_VERSION,
    ])->save(true);
    $grantId = (int)$grantModel->getId();
    if ($grantId <= 0) {
        throw new \RuntimeException('failed to insert ObjectScopeGrant');
    }

    /** @var BackendUserAdministrationInterface $users */
    $users = $om->get(BackendUserAdministrationInterface::class);
    $existing = $users->findByUsername($username);
    if ($existing !== null) {
        // 只清旧用户，保留刚创建的 role/grant（token 碰撞时的安全路径）
        $oldUserId = (int)$existing->getId();
        if ($oldUserId > 1) {
            /** @var UserRole $userRole */
            $userRole = clone $om->get(UserRole::class);
            $userRole->clear()->where(UserRole::schema_fields_USER_ID, $oldUserId)->delete()->fetch();
            /** @var BackendUser $user */
            $user = clone $om->get(BackendUser::class);
            $user->clear()->where(BackendUser::schema_fields_ID, $oldUserId)->delete()->fetch();
        }
    }
    $record = $users->save(null, $username, $email, $password);
    $userId = (int)$record->getId();
    sec05_protect_ids($roleId, $userId);
    $users->assignRole($userId, $roleId);
    $users->setState($userId, true, false);

    /** @var ObjectScopeGrantStoreInterface $grantStore */
    $grantStore = $om->get(ObjectScopeGrantStoreInterface::class);
    $grants = $grantStore->findByRole($roleId);
    if ($grants === []) {
        throw new \RuntimeException('prepared role must hydrate at least one ObjectScopeGrant');
    }

    sec05_clear_acl_cache();
    $probe = sec05_read_probe();

    return [
        'role_id' => $roleId,
        'user_id' => $userId,
        'grant_id' => $grantId,
        'username' => $username,
        'password' => $password,
        'role_name' => $roleName,
        'token' => $token,
        'grant_version' => SEC05_GRANT_VERSION,
        'target_scope' => SystemConfig::SCOPE_GLOBAL,
        'probe' => $probe,
        'probe_module' => SEC05_PROBE_MODULE,
        'probe_area' => SEC05_PROBE_AREA,
        'probe_code' => 'test',
        'probe_key' => SEC05_PROBE_KEY,
    ];
}

/**
 * @return array{role_id:int,grant_id:int,revoked:bool,remaining_grants:int}
 */
function sec05_revoke(int $roleId, int $grantId): array
{
    if ($roleId <= 1) {
        throw new \RuntimeException('refusing revoke of role_id<=1');
    }
    $om = ObjectManager::getInstance();
    /** @var ObjectScopeGrant $grantModel */
    $grantModel = clone $om->get(ObjectScopeGrant::class);
    if ($grantId > 0) {
        $grantModel->clear()
            ->where(ObjectScopeGrant::schema_fields_ID, $grantId)
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
            ->delete()
            ->fetch();
    } else {
        $grantModel->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
            ->delete()
            ->fetch();
    }

    /** @var ObjectScopeGrantStoreInterface $grantStore */
    $grantStore = $om->get(ObjectScopeGrantStoreInterface::class);
    $remaining = $grantStore->findByRole($roleId);
    sec05_clear_acl_cache();

    return [
        'role_id' => $roleId,
        'grant_id' => $grantId,
        'revoked' => true,
        'remaining_grants' => \count($remaining),
    ];
}

function sec05_cleanup(int $roleId, int $userId): void
{
    if ($roleId > 0 && $roleId <= 1) {
        throw new \RuntimeException('refusing cleanup of role_id<=1');
    }
    if ($userId > 0 && $userId <= 1) {
        throw new \RuntimeException('refusing cleanup of user_id<=1');
    }

    $om = ObjectManager::getInstance();

    if ($userId > 1) {
        /** @var UserRole $userRole */
        $userRole = clone $om->get(UserRole::class);
        $userRole->clear()->where(UserRole::schema_fields_USER_ID, $userId)->delete()->fetch();

        /** @var BackendUser $user */
        $user = clone $om->get(BackendUser::class);
        $user->clear()->where(BackendUser::schema_fields_ID, $userId)->delete()->fetch();
    }

    if ($roleId > 1) {
        /** @var ObjectScopeGrant $grantModel */
        $grantModel = clone $om->get(ObjectScopeGrant::class);
        $grantModel->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
            ->delete()
            ->fetch();

        /** @var RoleAccess $access */
        $access = clone $om->get(RoleAccess::class);
        $access->reset()
            ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
            ->delete()
            ->fetch();

        /** @var UserRole $userRoleByRole */
        $userRoleByRole = clone $om->get(UserRole::class);
        $userRoleByRole->clear()->where(UserRole::schema_fields_ROLE_ID, $roleId)->delete()->fetch();

        /** @var Role $role */
        $role = clone $om->get(Role::class);
        $role->clear()->where(Role::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    }

    sec05_clear_acl_cache();
}

try {
    sec05_require_isolated_postgresql_clone();
    $input = sec05_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $prepared = sec05_prepare(isset($input['token']) ? (string)$input['token'] : null);
        sec05_output(['ok' => true, 'action' => 'prepare'] + $prepared);
        exit(0);
    }
    if ($action === 'revoke') {
        $roleId = (int)($input['role_id'] ?? 0);
        $grantId = (int)($input['grant_id'] ?? 0);
        if ($roleId <= 1) {
            throw new \InvalidArgumentException('revoke requires role_id > 1');
        }
        $revoked = sec05_revoke($roleId, $grantId);
        sec05_output(['ok' => true, 'action' => 'revoke'] + $revoked);
        exit(0);
    }
    if ($action === 'read_probe') {
        sec05_output(['ok' => true, 'action' => 'read_probe'] + sec05_read_probe());
        exit(0);
    }
    if ($action === 'restore_probe') {
        if (!\array_key_exists('value', $input)) {
            throw new \InvalidArgumentException('restore_probe requires value');
        }
        $restored = sec05_restore_probe($input['value']);
        sec05_output(['ok' => true, 'action' => 'restore_probe'] + $restored);
        exit(0);
    }
    if ($action === 'cleanup') {
        $roleId = (int)($input['role_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        if ($roleId <= 1 && $userId <= 1) {
            throw new \InvalidArgumentException('cleanup requires role_id/user_id > 1');
        }
        sec05_cleanup($roleId, $userId);
        sec05_output(['ok' => true, 'action' => 'cleanup', 'role_id' => $roleId, 'user_id' => $userId]);
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $e) {
    sec05_fail($e->getMessage());
}
