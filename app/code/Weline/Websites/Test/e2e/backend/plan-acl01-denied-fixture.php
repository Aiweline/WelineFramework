<?php
declare(strict_types=1);

/**
 * TEST-ACL-01 受限角色 fixture：有 website_list 路由权，无 ObjectScopeGrant。
 *
 * stdin JSON: { "action": "prepare"|"cleanup", "token"?: string, "role_id"?: int, "user_id"?: int }
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

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const ACL01_SOURCES = [
    'Weline_Websites::website_service', // menus 父级
    'Weline_Websites::website',         // menus 入口（登录后可落地）
    'Weline_Websites::website_list',    // pc：列表页 + Catalog Query backend_acl
];
const ACL01_ROLE_PREFIX = 'e2e_acl01_denied_';
const ACL01_USER_PREFIX = 'e2e_acl01_u_';

/**
 * @return array<string, mixed>
 */
function acl01_read_input(): array
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
function acl01_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function acl01_fail(string $message, int $code = 1): never
{
    acl01_output(['ok' => false, 'error' => $message]);
    exit($code);
}

function acl01_protect_ids(int $roleId, int $userId): void
{
    if ($roleId <= 1 || $userId <= 1) {
        throw new \RuntimeException('refusing to mutate protected role/user id <= 1');
    }
}

/**
 * 为正向对照临时补一条 Super Admin 的 All Sites 只读授权。
 *
 * @return array{role_id:int,grant_id:int,created:bool}
 */
function acl01_grant_all_sites(int $roleId): array
{
    if ($roleId !== 1) {
        throw new \InvalidArgumentException('grant_all_sites only accepts protected Super Admin role_id=1');
    }

    $om = ObjectManager::getInstance();
    /** @var ObjectScopeGrantStoreInterface $grantStore */
    $grantStore = $om->get(ObjectScopeGrantStoreInterface::class);
    foreach ($grantStore->findByRole($roleId) as $grant) {
        if ($grant->isAllSites
            && $grant->allowsAction(ObjectAction::LIST)
            && $grant->allowsAction(ObjectAction::VIEW)
            && $grant->allowsAction(ObjectAction::EXPORT)
        ) {
            return ['role_id' => $roleId, 'grant_id' => 0, 'created' => false];
        }
    }

    /** @var ObjectScopeGrant $grantModel */
    $grantModel = clone $om->get(ObjectScopeGrant::class);
    $grantModel->clear()->setData([
        ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
        ObjectScopeGrant::schema_fields_IS_ALL_SITES => 1,
        ObjectScopeGrant::schema_fields_SCOPE_KIND => null,
        ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
        ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
        ObjectScopeGrant::schema_fields_STORE_CODE => null,
        ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
        ObjectScopeGrant::schema_fields_ACTIONS => \json_encode(
            ObjectAction::ALL_SITES_READ_ACTIONS,
            JSON_UNESCAPED_UNICODE,
        ),
        ObjectScopeGrant::schema_fields_GRANT_VERSION => 1,
    ])->save(true);
    $grantId = (int)$grantModel->getId();
    if ($grantId <= 0) {
        throw new \RuntimeException('failed to create temporary All Sites grant');
    }

    return ['role_id' => $roleId, 'grant_id' => $grantId, 'created' => true];
}

function acl01_revoke_all_sites(int $roleId, int $grantId): void
{
    if ($roleId !== 1 || $grantId <= 0) {
        throw new \InvalidArgumentException('revoke_all_sites requires role_id=1 and grant_id>0');
    }

    /** @var ObjectScopeGrant $grantModel */
    $grantModel = clone ObjectManager::getInstance()->get(ObjectScopeGrant::class);
    $grantModel->clear()
        ->where(ObjectScopeGrant::schema_fields_ID, $grantId)
        ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
        ->where(ObjectScopeGrant::schema_fields_IS_ALL_SITES, 1)
        ->delete()
        ->fetch();
}

/**
 * @return array{role_id:int,user_id:int,username:string,password:string,role_name:string,token:string,object_grants:int}
 */
function acl01_prepare(?string $token): array
{
    $token = $token !== null && $token !== ''
        ? \preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? ''
        : '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(4)) . '_' . (string)\getmypid();
    }
    $roleName = ACL01_ROLE_PREFIX . $token;
    $username = ACL01_USER_PREFIX . $token;
    $email = $username . '@example.test';
    $password = 'Acl01!' . \bin2hex(\random_bytes(6));

    $om = ObjectManager::getInstance();

    /** @var Role $role */
    $role = clone $om->get(Role::class);
    $role->clear()->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
    if ((int)$role->getId() > 0) {
        acl01_cleanup((int)$role->getId(), 0);
        $role = clone $om->get(Role::class);
    }
    $role->clear()
        ->setRoleName($roleName)
        ->setRoleDescription('E2E ACL-01 denied object scope (auto)')
        ->save(true);
    $roleId = (int)$role->getId();
    if ($roleId <= 1) {
        throw new \RuntimeException('failed to create denied role');
    }

    /** @var RoleAccess $access */
    $access = clone $om->get(RoleAccess::class);
    $rows = [];
    foreach (ACL01_SOURCES as $sourceId) {
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

    /** @var BackendUserAdministrationInterface $users */
    $users = $om->get(BackendUserAdministrationInterface::class);
    $existing = $users->findByUsername($username);
    if ($existing !== null) {
        acl01_cleanup($roleId, (int)$existing->getId());
    }
    $record = $users->save(null, $username, $email, $password);
    $userId = (int)$record->getId();
    acl01_protect_ids($roleId, $userId);
    $users->assignRole($userId, $roleId);
    $users->setState($userId, true, false);

    /** @var ObjectScopeGrantStoreInterface $grantStore */
    $grantStore = $om->get(ObjectScopeGrantStoreInterface::class);
    $grants = $grantStore->findByRole($roleId);
    if ($grants !== []) {
        throw new \RuntimeException('denied role must have zero object grants');
    }

    return [
        'role_id' => $roleId,
        'user_id' => $userId,
        'username' => $username,
        'password' => $password,
        'role_name' => $roleName,
        'token' => $token,
        'object_grants' => 0,
    ];
}

function acl01_cleanup(int $roleId, int $userId): void
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
}

try {
    $input = acl01_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $prepared = acl01_prepare(isset($input['token']) ? (string)$input['token'] : null);
        acl01_output(['ok' => true, 'action' => 'prepare'] + $prepared);
        exit(0);
    }
    if ($action === 'grant_all_sites') {
        $granted = acl01_grant_all_sites((int)($input['role_id'] ?? 0));
        acl01_output(['ok' => true, 'action' => 'grant_all_sites'] + $granted);
        exit(0);
    }
    if ($action === 'revoke_all_sites') {
        $roleId = (int)($input['role_id'] ?? 0);
        $grantId = (int)($input['grant_id'] ?? 0);
        acl01_revoke_all_sites($roleId, $grantId);
        acl01_output([
            'ok' => true,
            'action' => 'revoke_all_sites',
            'role_id' => $roleId,
            'grant_id' => $grantId,
        ]);
        exit(0);
    }
    if ($action === 'cleanup') {
        $roleId = (int)($input['role_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        if ($roleId <= 1 && $userId <= 1) {
            throw new \InvalidArgumentException('cleanup requires role_id/user_id > 1');
        }
        acl01_cleanup($roleId, $userId);
        acl01_output(['ok' => true, 'action' => 'cleanup', 'role_id' => $roleId, 'user_id' => $userId]);
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $e) {
    acl01_fail($e->getMessage());
}
