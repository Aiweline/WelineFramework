<?php
declare(strict_types=1);

/**
 * TEST-ACL-02 夹具：有权/无权后台用户 + scoped urgent 域名/支付严重事件。
 *
 * stdin JSON:
 *   { "action": "prepare"|"cleanup",
 *     "token"?: string,
 *     "authorized"?: {role_id,user_id},
 *     "denied"?: {role_id,user_id},
 *     "notification_id"?: int,
 *     "dedupe_key"?: string }
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
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Service\ScopedNotificationWriter;
use Weline\Backend\Service\ScopedUrgentNotifier;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const ACL02_SOURCES = [
    'Weline_Backend::notification_settings',
    'Weline_Backend::notification',
    'Weline_Backend::notification_index',
    'Weline_Backend::notification_detail',
];
const ACL02_AUTH_ROLE_PREFIX = 'e2e_acl02_auth_';
const ACL02_DENY_ROLE_PREFIX = 'e2e_acl02_deny_';
const ACL02_AUTH_USER_PREFIX = 'e2e_acl02_a_';
const ACL02_DENY_USER_PREFIX = 'e2e_acl02_d_';
const ACL02_GRANT_VERSION = 11;
const ACL02_TOPIC = 'domain_pool_resolve_off_local';

/**
 * @return array<string, mixed>
 */
function acl02_read_input(): array
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
function acl02_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function acl02_fail(string $message, int $code = 1): never
{
    acl02_output(['ok' => false, 'error' => $message]);
    exit($code);
}

function acl02_protect_ids(int $roleId, int $userId): void
{
    if ($roleId <= 1 || $userId <= 1) {
        throw new \RuntimeException('refusing to mutate protected role/user id <= 1');
    }
}

function acl02_clear_acl_cache(): void
{
    try {
        w_cache('acl')->clear();
    } catch (\Throwable) {
        // best-effort
    }
}

/**
 * @return array{role_id:int,user_id:int,username:string,password:string,role_name:string,grant_id:int}
 */
function acl02_create_user(
    string $rolePrefix,
    string $userPrefix,
    string $token,
    string $description,
    bool $withWebsiteViewGrant,
): array {
    $roleName = $rolePrefix . $token;
    $username = $userPrefix . $token;
    $email = $username . '@example.test';
    $password = 'Acl02!' . \bin2hex(\random_bytes(6));
    $om = ObjectManager::getInstance();

    /** @var Role $role */
    $role = clone $om->get(Role::class);
    $role->clear()->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
    if ((int)$role->getId() > 0) {
        acl02_cleanup_identity((int)$role->getId(), 0);
        $role = clone $om->get(Role::class);
    }
    $role->clear()
        ->setRoleName($roleName)
        ->setRoleDescription($description)
        ->save(true);
    $roleId = (int)$role->getId();
    if ($roleId <= 1) {
        throw new \RuntimeException('failed to create role: ' . $roleName);
    }

    /** @var RoleAccess $access */
    $access = clone $om->get(RoleAccess::class);
    $rows = [];
    foreach (ACL02_SOURCES as $sourceId) {
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

    $grantId = 0;
    if ($withWebsiteViewGrant) {
        $grantModel->clear()->setData([
            ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_WEBSITE,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => 0,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => 'default',
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => \json_encode(
                [ObjectAction::VIEW, ObjectAction::LIST],
                JSON_UNESCAPED_UNICODE,
            ),
            ObjectScopeGrant::schema_fields_GRANT_VERSION => ACL02_GRANT_VERSION,
        ])->save(true);
        $grantId = (int)$grantModel->getId();
        if ($grantId <= 0) {
            throw new \RuntimeException('failed to insert website VIEW grant');
        }
    }

    /** @var BackendUserAdministrationInterface $users */
    $users = $om->get(BackendUserAdministrationInterface::class);
    $existing = $users->findByUsername($username);
    if ($existing !== null) {
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
    acl02_protect_ids($roleId, $userId);
    $users->assignRole($userId, $roleId);
    $users->setState($userId, true, false);

    if ($withWebsiteViewGrant) {
        /** @var ObjectScopeGrantStoreInterface $grantStore */
        $grantStore = $om->get(ObjectScopeGrantStoreInterface::class);
        if ($grantStore->findByRole($roleId) === []) {
            throw new \RuntimeException('authorized role must hydrate ObjectScopeGrant');
        }
    }

    return [
        'role_id' => $roleId,
        'user_id' => $userId,
        'username' => $username,
        'password' => $password,
        'role_name' => $roleName,
        'grant_id' => $grantId,
    ];
}

/**
 * @return array<string, mixed>
 */
function acl02_prepare(?string $token): array
{
    $token = $token !== null && $token !== ''
        ? \preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? ''
        : '';
    if ($token === '') {
        $token = \bin2hex(\random_bytes(4)) . '_' . (string)\getmypid();
    }

    $authorized = acl02_create_user(
        ACL02_AUTH_ROLE_PREFIX,
        ACL02_AUTH_USER_PREFIX,
        $token,
        'E2E ACL-02 authorized website VIEW (auto)',
        true,
    );
    $denied = acl02_create_user(
        ACL02_DENY_ROLE_PREFIX,
        ACL02_DENY_USER_PREFIX,
        $token,
        'E2E ACL-02 denied no ObjectScopeGrant (auto)',
        false,
    );

    acl02_clear_acl_cache();

    $scope = ScopeIdentity::website(0, 'default');
    $dedupeKey = 'acl02_domain_off_local:' . $token;
    $title = 'ACL02域名严重事件_' . $token;
    $sensitiveNeedle = 'e2e-acl02.example.test';
    $sensitive = 'payment_callback_failure summary token=' . $token
        . ' domain=' . $sensitiveNeedle . ' ip=203.0.113.10';

    /** @var ScopedUrgentNotifier $notifier */
    $notifier = ObjectManager::getInstance()->get(ScopedUrgentNotifier::class);
    // null = 按 ACL VIEW 解析收件人（有权入广播，无权仅不得见）
    $notifier->emit(
        ACL02_TOPIC,
        $title,
        $sensitive,
        $scope,
        $dedupeKey,
        [
            'source_module' => 'Weline_Websites',
            'event' => 'domain_pool_resolve_off_local',
            'e2e_token' => $token,
        ],
        null,
    );
    // 同 dedupe 再发一次：occurrence_count 递增，UI 仍一条
    $notifier->emit(
        ACL02_TOPIC,
        $title,
        $sensitive . ' (retry)',
        $scope,
        $dedupeKey,
        [
            'source_module' => 'Weline_Websites',
            'event' => 'domain_pool_resolve_off_local',
            'e2e_token' => $token,
            'retry' => true,
        ],
        null,
    );

    /** @var SystemNotification $notificationModel */
    $notificationModel = ObjectManager::getInstance()->get(SystemNotification::class);
    $row = (clone $notificationModel)->clearData()->reset()
        ->where(SystemNotification::schema_fields_dedupe_key, $dedupeKey)
        ->find()
        ->fetch();
    $notificationId = (int)$row->getId();
    if ($notificationId <= 0) {
        throw new \RuntimeException('scoped urgent did not persist SystemNotification');
    }
    $occurrence = (int)$row->getOccurrenceCount();
    if ($occurrence < 2) {
        throw new \RuntimeException('expected occurrence_count>=2 after dedupe emit, got ' . $occurrence);
    }

    /** @var UserNotificationStatus $statusModel */
    $statusModel = ObjectManager::getInstance()->get(UserNotificationStatus::class);
    $authStatuses = (clone $statusModel)->clearData()->reset()
        ->where(UserNotificationStatus::schema_fields_notification_id, $notificationId)
        ->where(UserNotificationStatus::schema_fields_user_id, $authorized['user_id'])
        ->select()
        ->fetchArray();
    if ($authStatuses === []) {
        throw new \RuntimeException('authorized user must receive UserNotificationStatus broadcast');
    }
    $denyStatuses = (clone $statusModel)->clearData()->reset()
        ->where(UserNotificationStatus::schema_fields_notification_id, $notificationId)
        ->where(UserNotificationStatus::schema_fields_user_id, $denied['user_id'])
        ->select()
        ->fetchArray();
    if ($denyStatuses !== []) {
        throw new \RuntimeException('denied user must not receive UserNotificationStatus');
    }

    return [
        'token' => $token,
        'title' => $title,
        'sensitive_needle' => $sensitiveNeedle,
        'dedupe_key' => $dedupeKey,
        'notification_id' => $notificationId,
        'occurrence_count' => $occurrence,
        'scope_hash' => ScopedNotificationWriter::hashScopeIdentity($scope),
        'topic' => ACL02_TOPIC,
        'authorized' => $authorized,
        'denied' => $denied,
    ];
}

function acl02_cleanup_identity(int $roleId, int $userId): void
{
    if ($roleId > 0 && $roleId <= 1) {
        throw new \RuntimeException('refusing cleanup of role_id<=1');
    }
    if ($userId > 0 && $userId <= 1) {
        throw new \RuntimeException('refusing cleanup of user_id<=1');
    }

    $om = ObjectManager::getInstance();

    if ($userId > 1) {
        /** @var UserNotificationStatus $statusModel */
        $statusModel = clone $om->get(UserNotificationStatus::class);
        $statusModel->clear()
            ->where(UserNotificationStatus::schema_fields_user_id, $userId)
            ->delete()
            ->fetch();

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

/**
 * @param array<string, mixed> $input
 */
function acl02_cleanup(array $input): void
{
    $notificationId = (int)($input['notification_id'] ?? 0);
    $dedupeKey = (string)($input['dedupe_key'] ?? '');
    $om = ObjectManager::getInstance();

    if ($notificationId > 0) {
        /** @var UserNotificationStatus $statusModel */
        $statusModel = clone $om->get(UserNotificationStatus::class);
        $statusModel->clear()
            ->where(UserNotificationStatus::schema_fields_notification_id, $notificationId)
            ->delete()
            ->fetch();

        /** @var SystemNotification $notificationModel */
        $notificationModel = clone $om->get(SystemNotification::class);
        $notificationModel->clear()
            ->where(SystemNotification::schema_fields_ID, $notificationId)
            ->delete()
            ->fetch();
    } elseif ($dedupeKey !== '') {
        /** @var SystemNotification $notificationModel */
        $notificationModel = clone $om->get(SystemNotification::class);
        $row = (clone $notificationModel)->clearData()->reset()
            ->where(SystemNotification::schema_fields_dedupe_key, $dedupeKey)
            ->find()
            ->fetch();
        $id = (int)$row->getId();
        if ($id > 0) {
            /** @var UserNotificationStatus $statusModel */
            $statusModel = clone $om->get(UserNotificationStatus::class);
            $statusModel->clear()
                ->where(UserNotificationStatus::schema_fields_notification_id, $id)
                ->delete()
                ->fetch();
            (clone $notificationModel)->clear()
                ->where(SystemNotification::schema_fields_ID, $id)
                ->delete()
                ->fetch();
        }
    }

    foreach (['authorized', 'denied'] as $key) {
        $bucket = $input[$key] ?? null;
        if (!\is_array($bucket)) {
            continue;
        }
        $roleId = (int)($bucket['role_id'] ?? 0);
        $userId = (int)($bucket['user_id'] ?? 0);
        if ($roleId > 1 || $userId > 1) {
            acl02_cleanup_identity($roleId, $userId);
        }
    }

    acl02_clear_acl_cache();
}

try {
    $input = acl02_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $prepared = acl02_prepare(isset($input['token']) ? (string)$input['token'] : null);
        acl02_output(['ok' => true, 'action' => 'prepare'] + $prepared);
        exit(0);
    }
    if ($action === 'cleanup') {
        acl02_cleanup($input);
        acl02_output(['ok' => true, 'action' => 'cleanup']);
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $e) {
    acl02_fail($e->getMessage());
}
