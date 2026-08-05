<?php

declare(strict_types=1);

use Weline\Acl\Model\AclTag;
use Weline\Acl\Model\IpWhitelist;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Acl\Model\RoleTagGrant;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function r43_acl_write_require_isolated_clone(): string
{
    if ((string)\getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new \RuntimeException('R4.3 ACL write fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $type = \strtolower((string)($env['db']['master']['type'] ?? ''));
    if ($type !== 'pgsql') {
        throw new \RuntimeException('R4.3 ACL write fixture requires PostgreSQL, got: ' . $type);
    }
    $database = (string)($env['db']['master']['database'] ?? '');
    if (\preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new \RuntimeException('R4.3 ACL write fixture refuses non-clone database: ' . $database);
    }
    return $database;
}

/** @return array<string,mixed> */
function r43_acl_write_input(): array
{
    $decoded = \json_decode((string)\file_get_contents('php://stdin'), true);
    if (!\is_array($decoded)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_acl_write_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

/** @return array<string,mixed> */
function r43_acl_write_prepare(?string $requestedToken): array
{
    $token = \preg_replace('/[^a-f0-9]/i', '', (string)$requestedToken) ?: \bin2hex(\random_bytes(6));
    $token = \strtolower(\substr($token, 0, 12));
    $roleName = 'e2e_r43_acl_role_' . $token;
    $ip = '2001:db8:' . \substr($token . '0000', 0, 4) . ':' . \substr($token . '00000000', 4, 4) . '::1';
    $tag = 'commerce';

    r43_acl_write_delete_role($roleName);
    ObjectManager::getInstance(IpWhitelist::class, [], false)
        ->reset()->where(IpWhitelist::schema_fields_IP, $ip)->delete()->fetch();

    $tagRow = ObjectManager::getInstance(AclTag::class, [], false)
        ->reset()->where(AclTag::schema_fields_TAG, $tag)->find()->fetch();
    $originalTag = [
        'exists' => (int)$tagRow->getId() > 0,
        'tag' => $tag,
        'display_name' => (string)$tagRow->getData(AclTag::schema_fields_DISPLAY_NAME),
        'description' => (string)$tagRow->getData(AclTag::schema_fields_DESCRIPTION),
        'color' => (string)$tagRow->getData(AclTag::schema_fields_COLOR),
        'sort_order' => (int)$tagRow->getData(AclTag::schema_fields_SORT_ORDER),
    ];

    return [
        'token' => $token,
        'role_name' => $roleName,
        'role_description' => 'R4.3 ACL WebUI ' . $token,
        'tag' => $tag,
        'tag_display_name' => 'Commerce R43 ' . $token,
        'tag_description' => 'R4.3 WebUI metadata ' . $token,
        'tag_color' => '#2f80ed',
        'tag_sort_order' => 43,
        'original_tag' => $originalTag,
        'ip' => $ip,
        'ip_description' => 'R4.3 disabled WebUI fixture ' . $token,
    ];
}

function r43_acl_write_delete_role(string $roleName): int
{
    $role = ObjectManager::getInstance(Role::class, [], false)
        ->reset()->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
    $roleId = (int)$role->getId();
    if ($roleId <= 1) {
        return 0;
    }
    ObjectManager::getInstance(RoleAccess::class, [], false)
        ->reset()->where(RoleAccess::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    ObjectManager::getInstance(RoleTagGrant::class, [], false)
        ->reset()->where(RoleTagGrant::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    ObjectManager::getInstance(UserRole::class, [], false)
        ->reset()->where(UserRole::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    ObjectManager::getInstance(Role::class, [], false)
        ->reset()->where(Role::schema_fields_ROLE_ID, $roleId)->delete()->fetch();
    return 1;
}

/** @param array<string,mixed> $input */
function r43_acl_write_assert(array $input): array
{
    $kind = (string)($input['kind'] ?? '');
    if ($kind === 'role') {
        $name = (string)($input['role_name'] ?? '');
        $row = ObjectManager::getInstance(Role::class, [], false)
            ->reset()->where(Role::schema_fields_ROLE_NAME, $name)->find()->fetch();
        if ((int)$row->getId() <= 1 || (string)$row->getData(Role::schema_fields_ROLE_DESCRIPTION) !== (string)($input['role_description'] ?? '')) {
            throw new \RuntimeException('role was not persisted by the browser action');
        }
        return ['kind' => $kind, 'role_id' => (int)$row->getId(), 'role_name' => $name];
    }
    if ($kind === 'tag') {
        $tag = (string)($input['tag'] ?? '');
        $row = ObjectManager::getInstance(AclTag::class, [], false)
            ->reset()->where(AclTag::schema_fields_TAG, $tag)->find()->fetch();
        if ((string)$row->getData(AclTag::schema_fields_DISPLAY_NAME) !== (string)($input['tag_display_name'] ?? '')
            || (string)$row->getData(AclTag::schema_fields_DESCRIPTION) !== (string)($input['tag_description'] ?? '')
            || (string)$row->getData(AclTag::schema_fields_COLOR) !== (string)($input['tag_color'] ?? '')
            || (int)$row->getData(AclTag::schema_fields_SORT_ORDER) !== (int)($input['tag_sort_order'] ?? -1)
        ) {
            throw new \RuntimeException('tag metadata was not persisted by the browser action');
        }
        return ['kind' => $kind, 'tag_id' => (int)$row->getId(), 'tag' => $tag];
    }
    if ($kind === 'ip') {
        $ip = (string)($input['ip'] ?? '');
        $row = ObjectManager::getInstance(IpWhitelist::class, [], false)
            ->reset()->where(IpWhitelist::schema_fields_IP, $ip)->find()->fetch();
        if ((int)$row->getId() <= 0
            || (string)$row->getData(IpWhitelist::schema_fields_DESCRIPTION) !== (string)($input['ip_description'] ?? '')
            || (int)$row->getData(IpWhitelist::schema_fields_IS_ACTIVE) !== 0
        ) {
            throw new \RuntimeException('inactive IP whitelist row was not persisted by the browser action');
        }
        return ['kind' => $kind, 'id' => (int)$row->getId(), 'ip' => $ip, 'is_active' => 0];
    }
    throw new \InvalidArgumentException('unknown assertion kind: ' . $kind);
}

/** @param array<string,mixed> $input */
function r43_acl_write_cleanup(array $input): array
{
    $deletedRole = r43_acl_write_delete_role((string)($input['role_name'] ?? ''));
    $ip = (string)($input['ip'] ?? '');
    $deletedIp = 0;
    if ($ip !== '') {
        $probe = ObjectManager::getInstance(IpWhitelist::class, [], false)
            ->reset()->where(IpWhitelist::schema_fields_IP, $ip)->find()->fetch();
        $deletedIp = (int)$probe->getId() > 0 ? 1 : 0;
        ObjectManager::getInstance(IpWhitelist::class, [], false)
            ->reset()->where(IpWhitelist::schema_fields_IP, $ip)->delete()->fetch();
    }

    $original = $input['original_tag'] ?? null;
    if (\is_array($original) && (string)($original['tag'] ?? '') !== '') {
        $tag = (string)$original['tag'];
        ObjectManager::getInstance(AclTag::class, [], false)
            ->reset()->where(AclTag::schema_fields_TAG, $tag)->delete()->fetch();
        if (!empty($original['exists'])) {
            ObjectManager::getInstance(AclTag::class, [], false)->clear()->setData([
                AclTag::schema_fields_TAG => $tag,
                AclTag::schema_fields_DISPLAY_NAME => (string)($original['display_name'] ?? ''),
                AclTag::schema_fields_DESCRIPTION => (string)($original['description'] ?? ''),
                AclTag::schema_fields_COLOR => (string)($original['color'] ?? ''),
                AclTag::schema_fields_SORT_ORDER => (int)($original['sort_order'] ?? 0),
            ])->save(true);
        }
    }

    return ['deleted_role' => $deletedRole, 'deleted_ip' => $deletedIp, 'tag_restored' => \is_array($original)];
}

try {
    r43_acl_write_require_isolated_clone();
    $input = r43_acl_write_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        r43_acl_write_output(['ok' => true, 'action' => $action] + r43_acl_write_prepare($input['token'] ?? null));
        exit(0);
    }
    if ($action === 'assert') {
        r43_acl_write_output(['ok' => true, 'action' => $action] + r43_acl_write_assert($input));
        exit(0);
    }
    if ($action === 'cleanup') {
        r43_acl_write_output(['ok' => true, 'action' => $action] + r43_acl_write_cleanup($input));
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $error) {
    r43_acl_write_output(['ok' => false, 'error' => $error->getMessage()]);
    exit(1);
}
