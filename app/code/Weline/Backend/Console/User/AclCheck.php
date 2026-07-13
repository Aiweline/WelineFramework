<?php
declare(strict_types=1);

/**
 * 对比后端用户/角色/权限数据与 getAclContext 读取结果，用于排查「没角色/没权限」问题。
 * 用法：php bin/w user:acl-check [--user_id=3]
 */
namespace Weline\Backend\Console\User;

use Weline\Acl\Api\Authorization\AuthorizationServiceInterface;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class AclCheck implements CommandInterface
{
    public function __construct(
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $formatArgs = $args['format'] ?? [];
        array_shift($formatArgs);
        $userIdArg = $formatArgs['user_id'] ?? $args['user_id'] ?? null;

        /** @var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);

        $userIds = $userIdArg !== null && $userIdArg !== '' ? [(int) $userIdArg] : [1, 3];

        /** @var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        /** @var UserRole $urModel */
        $urModel = ObjectManager::getInstance(UserRole::class);
        $printer->printing('========== 1. backend_user 表（逻辑名 backend_user，实际带前缀） ==========');
        foreach ($userIds as $uid) {
            $user = clone $userModel;
            $user->reset()->load($uid);
            if (!$user->getId()) {
                $printer->warning("  user_id={$uid}: 不存在");
                continue;
            }
            $printer->printing(sprintf(
                '  user_id=%d  username=%s  is_enabled=%d  is_deleted=%d',
                (int) $user->getId(),
                $user->getUsername() ?? '',
                (int) $user->getData(BackendUser::schema_fields_is_enabled),
                (int) $user->getData(BackendUser::schema_fields_is_deleted)
            ));
        }

        $printer->printing('');
        $printer->printing('========== 2. backend_acl_user_role 表（逻辑名 backend_acl_user_role） ==========');
        foreach ($userIds as $uid) {
            $ur = clone $urModel;
            $rows = $ur->reset()->where(UserRole::schema_fields_USER_ID, $uid)->select()->fetchArray();
            if (empty($rows)) {
                $printer->warning("  user_id={$uid}: 无记录（即未分配角色）");
                continue;
            }
            foreach ($rows as $row) {
                $rid = $row[UserRole::schema_fields_ROLE_ID] ?? $row['role_id'] ?? '';
                $printer->printing("  user_id={$uid}  role_id={$rid}");
            }
        }

        $printer->printing('');
        $printer->printing('========== 3. getAclContext(userId) 代码读取结果（用于 RouteBefore 判断） ==========');
        foreach ($userIds as $uid) {
            $ctx = BackendUser::getAclContext($uid);
            if ($ctx === null) {
                $printer->warning("  getAclContext({$uid}): null（用户不存在或被删除）");
                continue;
            }
            $printer->printing(sprintf(
                '  getAclContext(%d): user_id=%d role_id=%d is_enabled=%d',
                $uid,
                $ctx['user_id'],
                $ctx['role_id'],
                $ctx['is_enabled']
            ));
            $roleId = $ctx['role_id'];
            if ($roleId <= 0) {
                $printer->warning('    -> role_id<=0，RouteBefore 会走 no_role（用户没有分配角色）');
            } else {
                $hasAny = $this->authorizationService->hasAnyPermission($roleId);
                $printer->printing('    -> hasAnyPermission(' . $roleId . ') = ' . ($hasAny ? 'true' : 'false'));
                if (!$hasAny) {
                    $printer->warning('    -> RouteBefore 会走 no_any_permission（角色没有任何 ACL 权限）');
                }
            }
        }

        $printer->printing('');
        $printer->success('对比结论：若 2 中无记录而 3 中 role_id=0，则为数据缺角色；若 2 有记录但 3 仍 role_id=0，则为代码读取问题（如表前缀/JOIN）。');
    }

    public function tip(): string
    {
        return __('对比后端用户/角色数据与 getAclContext 结果，排查没角色或没权限。')
            . ' php bin/w user:acl-check 或 --user_id=3';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            ['--user_id=' => __('指定用户ID，不传则查 1 和 3')],
            [],
            []
        );
    }
}
