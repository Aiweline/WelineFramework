<?php

declare(strict_types=1);

use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Acl\Model\RoleTagGrant;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Service\Resource\RoleTagGrantSyncService;
use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const R43_MANIFEST = 'tests/e2e/manifests/commerce-kernel-r43.json';
const R43_PROTECTED_ID_MAX = 1;

function r43_require_isolated_clone(): string
{
    if ((string)\getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new \RuntimeException('R4.3 ACL fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $type = \strtolower((string)($env['db']['master']['type'] ?? ''));
    if ($type !== 'pgsql') {
        throw new \RuntimeException('R4.3 ACL fixture requires PostgreSQL, got: ' . $type);
    }
    $database = (string)($env['db']['master']['database'] ?? '');
    if (\preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new \RuntimeException('R4.3 ACL fixture refuses non-clone database: ' . $database);
    }
    return $database;
}

/** @return array<string,mixed> */
function r43_input(): array
{
    $raw = \file_get_contents('php://stdin');
    $input = \json_decode((string)$raw, true);
    if (!\is_array($input)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }
    return $input;
}

/** @param array<string,mixed> $payload */
function r43_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function r43_clear_acl_cache(): void
{
    try {
        w_cache('acl')->clear();
    } catch (\Throwable) {
        // Best effort; every browser identity receives a fresh session.
    }
}

function r43_acl_state_hash(): string
{
    $access = ObjectManager::getInstance(RoleAccess::class, [], false)
        ->reset()
        ->fields([RoleAccess::schema_fields_ROLE_ID, RoleAccess::schema_fields_SOURCE_ID])
        ->select()
        ->fetchArray();
    $tags = ObjectManager::getInstance(RoleTagGrant::class, [], false)
        ->reset()
        ->fields([RoleTagGrant::schema_fields_ROLE_ID, RoleTagGrant::schema_fields_TAG_PATH])
        ->select()
        ->fetchArray();
    $objectGrants = ObjectManager::getInstance(ObjectScopeGrant::class, [], false)
        ->reset()
        ->fields([
            ObjectScopeGrant::schema_fields_ROLE_ID,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES,
            ObjectScopeGrant::schema_fields_SCOPE_KIND,
            ObjectScopeGrant::schema_fields_WEBSITE_ID,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE,
            ObjectScopeGrant::schema_fields_STORE_CODE,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE,
            ObjectScopeGrant::schema_fields_ACTIONS,
            ObjectScopeGrant::schema_fields_GRANT_VERSION,
        ])
        ->select()
        ->fetchArray();
    $normalize = static function (array $rows, string $roleField, string $valueField): array {
        $values = \array_map(
            static fn(array $row): string => (int)($row[$roleField] ?? 0) . "\0" . (string)($row[$valueField] ?? ''),
            $rows,
        );
        \sort($values, SORT_STRING);
        return $values;
    };
    $objectGrantState = \array_values(\array_map(
        static function (array $row): string {
            \ksort($row);
            return \json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        },
        $objectGrants,
    ));
    \sort($objectGrantState, SORT_STRING);
    return \hash('sha256', \json_encode([
        'access' => $normalize($access, RoleAccess::schema_fields_ROLE_ID, RoleAccess::schema_fields_SOURCE_ID),
        'tags' => $normalize($tags, RoleTagGrant::schema_fields_ROLE_ID, RoleTagGrant::schema_fields_TAG_PATH),
        'object_grants' => $objectGrantState,
    ], JSON_THROW_ON_ERROR));
}

function r43_grant_full_object_scopes(int $roleId): void
{
    if ($roleId <= R43_PROTECTED_ID_MAX) {
        throw new \InvalidArgumentException('invalid full-role object grant target');
    }
    $actions = \array_values(\array_diff(ObjectAction::ALL, [ObjectAction::ALL_SITES]));
    $actionsJson = \json_encode($actions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $rows = [
        [
            ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_GLOBAL,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => $actionsJson,
            ObjectScopeGrant::schema_fields_GRANT_VERSION => 1,
        ],
        [
            ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_WEBSITE,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => 0,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => 'default',
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => $actionsJson,
            ObjectScopeGrant::schema_fields_GRANT_VERSION => 1,
        ],
    ];
    foreach ($rows as $row) {
        ObjectManager::getInstance(ObjectScopeGrant::class, [], false)
            ->clear()
            ->setData($row)
            ->save(true);
    }
}

/** @return array<string,mixed> */
function r43_manifest(): array
{
    $path = BP . R43_MANIFEST;
    if (!\is_file($path)) {
        throw new \RuntimeException('R4.3 capability manifest is missing: ' . $path);
    }
    $decoded = \json_decode((string)\file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!\is_array($decoded) || !\is_array($decoded['capabilities'] ?? null)) {
        throw new \RuntimeException('R4.3 capability manifest has no capabilities list');
    }
    return $decoded;
}

/** @return list<string> */
function r43_full_sources(): array
{
    $sources = [
        'Weline_Backend::dashboard',
        'Weline_Backend::business_operations',
        'Weline_Backend::system_management',
        'Weline_Backend::apps_tools',
    ];
    foreach (r43_manifest()['capabilities'] as $capability) {
        if (!\is_array($capability) || ($capability['layer'] ?? 'webui') === 'non-ui') {
            continue;
        }
        foreach (['sourceId', 'parentSource'] as $key) {
            $source = \trim((string)($capability[$key] ?? ''));
            if ($source !== '') {
                $sources[] = $source;
            }
        }
    }
    /** A full-permission profile must also grant controller action resources. */
    $aclRows = ObjectManager::getInstance(Acl::class, [], false)
        ->reset()
        ->fields([Acl::schema_fields_SOURCE_ID])
        ->select()
        ->fetchArray();
    foreach ($aclRows as $row) {
        $source = \trim((string)($row[Acl::schema_fields_SOURCE_ID] ?? ''));
        if ($source !== '') {
            $sources[] = $source;
        }
    }
    return r43_expand_menu_ancestors(\array_values(\array_unique($sources)));
}

/**
 * ACL filtering is applied at every level of the sidebar. Grant all ancestor
 * containers required to reach a leaf instead of relying on implicit parent
 * visibility.
 *
 * @param list<string> $sources
 * @return list<string>
 */
function r43_expand_menu_ancestors(array $sources): array
{
    $parents = [];
    $walk = static function (iterable $nodes, string $nestedParent = '') use (&$walk, &$parents): void {
        foreach ($nodes as $node) {
            $source = \trim((string)$node['source']);
            $parent = \trim((string)$node['parent']);
            if ($parent === '') {
                $parent = $nestedParent;
            }
            if ($source !== '' && $parent !== '') {
                $parents[$source] = $parent;
            }
            $walk($node->menu, $source);
        }
    };

    foreach (\glob(BP . 'app/code/Weline/*/etc/backend/menu.xml') ?: [] as $file) {
        $document = \simplexml_load_file($file);
        if ($document === false) {
            throw new \RuntimeException('invalid menu XML while preparing ACL fixture: ' . $file);
        }
        $walk($document->menu);
    }

    $expanded = [];
    foreach ($sources as $source) {
        $cursor = $source;
        $seen = [];
        while ($cursor !== '' && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $expanded[$cursor] = true;
            $cursor = $parents[$cursor] ?? '';
        }
    }
    return \array_keys($expanded);
}

function r43_cleanup_identity(int $roleId, int $userId): void
{
    if (($roleId > 0 && $roleId <= R43_PROTECTED_ID_MAX)
        || ($userId > 0 && $userId <= R43_PROTECTED_ID_MAX)
    ) {
        throw new \RuntimeException('refusing to delete protected role/user id');
    }

    $om = ObjectManager::getInstance();
    if ($userId > R43_PROTECTED_ID_MAX) {
        (clone $om->get(UserRole::class))->clear()
            ->where(UserRole::schema_fields_USER_ID, $userId)->delete()->fetch();
        (clone $om->get(BackendUser::class))->clear()
            ->where(BackendUser::schema_fields_ID, $userId)->delete()->fetch();
    }
    if ($roleId > R43_PROTECTED_ID_MAX) {
        (clone $om->get(ObjectScopeGrant::class))->reset()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
        (clone $om->get(RoleTagGrant::class))->reset()
            ->where(RoleTagGrant::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
        (clone $om->get(RoleAccess::class))->reset()
            ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
        (clone $om->get(UserRole::class))->clear()
            ->where(UserRole::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
        (clone $om->get(Role::class))->clear()
            ->where(Role::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    }
}

/** @param array<string,mixed> $identity @return array<string,int> */
function r43_identity_residue(array $identity): array
{
    $roleId = (int)($identity['role_id'] ?? 0);
    $userId = (int)($identity['user_id'] ?? 0);
    $roleName = (string)($identity['role_name'] ?? '');
    $username = (string)($identity['username'] ?? '');
    $count = static function (string $class, array $where): int {
        $model = ObjectManager::getInstance($class, [], false)->reset();
        foreach ($where as $field => $value) {
            $model->where((string)$field, $value);
        }
        return \count($model->select()->fetchArray());
    };

    return [
        'roles' => $roleName !== '' ? $count(Role::class, [Role::schema_fields_ROLE_NAME => $roleName]) : 0,
        'users' => $username !== '' ? $count(BackendUser::class, [BackendUser::schema_fields_username => $username]) : 0,
        'role_access' => $roleId > 0 ? $count(RoleAccess::class, [RoleAccess::schema_fields_ROLE_ID => $roleId]) : 0,
        'role_tags' => $roleId > 0 ? $count(RoleTagGrant::class, [RoleTagGrant::schema_fields_ROLE_ID => $roleId]) : 0,
        'object_grants' => $roleId > 0 ? $count(ObjectScopeGrant::class, [ObjectScopeGrant::schema_fields_ROLE_ID => $roleId]) : 0,
        'user_roles_by_role' => $roleId > 0 ? $count(UserRole::class, [UserRole::schema_fields_ROLE_ID => $roleId]) : 0,
        'user_roles_by_user' => $userId > 0 ? $count(UserRole::class, [UserRole::schema_fields_USER_ID => $userId]) : 0,
    ];
}

/**
 * @param list<string> $sources
 * @param list<string> $tagPaths
 * @return array{role_id:int,user_id:int,username:string,password:string,profile:string,role_name:string}
 */
function r43_create_identity(string $profile, string $token, array $sources, array $tagPaths = []): array
{
    $om = ObjectManager::getInstance();
    $roleName = 'e2e_r43_' . $profile . '_' . $token;
    $username = 'e2e_r43_' . \substr($profile, 0, 4) . '_' . $token;
    $password = 'R43!' . \bin2hex(\random_bytes(8));
    $roleId = 0;
    $userId = 0;

    try {

    /** @var Role $role */
    $role = clone $om->get(Role::class);
    $role->clear()->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
    if ((int)$role->getId() > R43_PROTECTED_ID_MAX) {
        r43_cleanup_identity((int)$role->getId(), 0);
        $role = clone $om->get(Role::class);
    }
    $role->clear()->setRoleName($roleName)
        ->setRoleDescription('R4.3 temporary ' . $profile . ' role')
        ->save(true);
    $roleId = (int)$role->getId();
    if ($roleId <= R43_PROTECTED_ID_MAX) {
        throw new \RuntimeException('failed to create temporary role');
    }

    $rows = [];
    foreach (\array_values(\array_unique($sources)) as $sourceId) {
        if ($sourceId !== '') {
            $rows[] = [
                RoleAccess::schema_fields_ROLE_ID => $roleId,
                RoleAccess::schema_fields_SOURCE_ID => $sourceId,
            ];
        }
    }
    if ($rows !== []) {
        (clone $om->get(RoleAccess::class))->reset()->insert($rows, [
            RoleAccess::schema_fields_ROLE_ID,
            RoleAccess::schema_fields_SOURCE_ID,
        ])->fetch();
    }

    foreach (\array_values(\array_unique($tagPaths)) as $tagPath) {
        (clone $om->get(RoleTagGrant::class))->clear()->setData([
            RoleTagGrant::schema_fields_ROLE_ID => $roleId,
            RoleTagGrant::schema_fields_TAG_PATH => $tagPath,
        ])->save(true);
    }
    if ($tagPaths !== []) {
        // Materialize only this temporary role. A global add-only sync could
        // otherwise mutate unrelated roles that happen to own tag grants.
        $om->get(RoleTagGrantSyncService::class)->syncAddOnly(null, [$roleId]);
    }

    /** @var BackendUserAdministrationInterface $users */
    $users = $om->get(BackendUserAdministrationInterface::class);
    $existing = $users->findByUsername($username);
    if ($existing !== null && (int)$existing->getId() > R43_PROTECTED_ID_MAX) {
        r43_cleanup_identity(0, (int)$existing->getId());
    }
    $user = $users->save(null, $username, $username . '@example.test', $password);
    $userId = (int)$user->getId();
    if ($userId <= R43_PROTECTED_ID_MAX) {
        throw new \RuntimeException('failed to create temporary backend user');
    }
    $users->assignRole($userId, $roleId);
    $users->setState($userId, true, false);

    return [
        'role_id' => $roleId,
        'user_id' => $userId,
        'username' => $username,
        'password' => $password,
        'profile' => $profile,
        'role_name' => $roleName,
    ];
    } catch (\Throwable $throwable) {
        if ($roleId > R43_PROTECTED_ID_MAX || $userId > R43_PROTECTED_ID_MAX) {
            try {
                r43_cleanup_identity($roleId, $userId);
            } catch (\Throwable) {
                // Preserve the original prepare error. Deterministic role/user
                // names allow the next isolated run to clean stale state.
            }
        }
        throw $throwable;
    }
}

/** @return array<string,mixed> */
function r43_prepare(?string $requestedToken): array
{
    $token = \preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$requestedToken) ?: '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(4)) . '_' . \getmypid();
    }

    $created = [];
    $aclStateHash = r43_acl_state_hash();
    try {
        $dashboard = ['Weline_Backend::dashboard'];
        $full = r43_create_identity('full', $token, r43_full_sources());
        $created[] = $full;
        r43_grant_full_object_scopes((int)$full['role_id']);
        $partial = r43_create_identity(
            'catalog',
            $token,
            \array_merge($dashboard, ['Weline_Backend::business_operations']),
            ['commerce:catalog'],
        );
        $created[] = $partial;
        $denied = r43_create_identity('denied', $token, $dashboard);
        $created[] = $denied;
        r43_clear_acl_cache();

        return [
            'token' => $token,
            'acl_state_hash' => $aclStateHash,
            'full' => $full,
            'partial' => $partial,
            'denied' => $denied,
        ];
    } catch (\Throwable $throwable) {
        foreach (\array_reverse($created) as $identity) {
            try {
                r43_cleanup_identity(
                    (int)($identity['role_id'] ?? 0),
                    (int)($identity['user_id'] ?? 0),
                );
            } catch (\Throwable) {
                // Preserve the first prepare failure.
            }
        }
        r43_clear_acl_cache();
        throw $throwable;
    }
}

/** @return array<string,mixed> */
function r43_prepare_rename(?string $requestedToken): array
{
    $token = \preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$requestedToken) ?: '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(4)) . '_' . \getmypid();
    }
    $identity = r43_create_identity('rename', $token, [
        'Weline_Checkout::order_list',
        'Weline_Checkout::order_view',
        'Weline_Checkout::order_update_status',
        'Weline_Order::status_index',
    ]);
    $rows = ObjectManager::getInstance(RoleAccess::class, [], false)
        ->reset()
        ->where(RoleAccess::schema_fields_ROLE_ID, (int)$identity['role_id'])
        ->select()
        ->fetchArray();
    $identity['before_sources'] = \array_values(\array_unique(\array_map(
        static fn(array $row): string => (string)($row[RoleAccess::schema_fields_SOURCE_ID] ?? ''),
        $rows,
    )));
    \sort($identity['before_sources']);
    return ['token' => $token, 'identity' => $identity];
}

/** @param array<string,mixed> $identity */
function r43_assert_rename(array $identity): array
{
    $roleId = (int)($identity['role_id'] ?? 0);
    if ($roleId <= R43_PROTECTED_ID_MAX) {
        throw new \InvalidArgumentException('invalid rename-test role');
    }
    $renameMap = [
        'Weline_Checkout::order_list' => 'Weline_Order::order_list',
        'Weline_Checkout::order_view' => 'Weline_Order::order_view',
        'Weline_Checkout::order_update_status' => 'Weline_Order::order_update_status',
        'Weline_Order::status_index' => 'Weline_Order::status_manage',
    ];
    $before = \array_values(\array_filter(
        \array_map('strval', (array)($identity['before_sources'] ?? [])),
    ));
    \sort($before);
    if ($before === []) {
        throw new \RuntimeException('rename ACL preimage is missing');
    }
    $expected = \array_values(\array_unique(\array_map(
        static fn(string $source): string => $renameMap[$source] ?? $source,
        $before,
    )));
    \sort($expected);
    $rows = ObjectManager::getInstance(RoleAccess::class, [], false)
        ->reset()
        ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
        ->select()
        ->fetchArray();
    $sources = \array_values(\array_unique(\array_map(
        static fn (array $row): string => (string)($row[RoleAccess::schema_fields_SOURCE_ID] ?? ''),
        $rows,
    )));
    \sort($sources);
    if ($sources !== $expected) {
        throw new \RuntimeException('ACL rename changed authorization set unexpectedly: ' . \json_encode([
            'before' => $before,
            'expected' => $expected,
            'actual' => $sources,
        ], JSON_UNESCAPED_SLASHES));
    }
    return ['role_id' => $roleId, 'before_sources' => $before, 'expected_sources' => $expected, 'sources' => $sources];
}

try {
    r43_require_isolated_clone();
    $input = r43_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        r43_output(['ok' => true, 'action' => 'prepare'] + r43_prepare($input['token'] ?? null));
        exit(0);
    }
    if ($action === 'prepare-rename') {
        r43_output(['ok' => true, 'action' => 'prepare-rename'] + r43_prepare_rename($input['token'] ?? null));
        exit(0);
    }
    if ($action === 'assert-rename') {
        $identity = $input['identity'] ?? null;
        if (!\is_array($identity)) {
            throw new \InvalidArgumentException('identity is required');
        }
        r43_output(['ok' => true, 'action' => 'assert-rename'] + r43_assert_rename($identity));
        exit(0);
    }
    if ($action === 'cleanup') {
        $remaining = [
            'roles' => 0,
            'users' => 0,
            'role_access' => 0,
            'role_tags' => 0,
            'object_grants' => 0,
            'user_roles_by_role' => 0,
            'user_roles_by_user' => 0,
        ];
        foreach (['full', 'partial', 'denied'] as $profile) {
            $identity = $input[$profile] ?? null;
            if (!\is_array($identity)) continue;
            r43_cleanup_identity(
                (int)($identity['role_id'] ?? 0),
                (int)($identity['user_id'] ?? 0),
            );
            foreach (r43_identity_residue($identity) as $key => $count) {
                $remaining[$key] += $count;
            }
        }
        r43_clear_acl_cache();
        if (\array_sum($remaining) !== 0) {
            throw new \RuntimeException('R4.3 ACL cleanup incomplete: ' . \json_encode($remaining));
        }
        $expectedAclHash = (string)($input['acl_state_hash'] ?? '');
        if ($expectedAclHash !== '' && !\hash_equals($expectedAclHash, r43_acl_state_hash())) {
            throw new \RuntimeException('R4.3 ACL cleanup changed unrelated role authorization state');
        }
        r43_output(['ok' => true, 'action' => 'cleanup', 'remaining' => $remaining]);
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $error) {
    r43_output(['ok' => false, 'error' => $error->getMessage()]);
    exit(1);
}
