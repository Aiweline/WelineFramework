<?php

declare(strict_types=1);

/*
 * 为指定后台用户分配角色（可指定任意角色，而非固定超管）。
 * 用法：php bin/w user:assign-role --username=xxx --role_id=2 或 --role=角色名
 */

namespace Weline\Backend\Console\User;

use Weline\Acl\Model\Role;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class AssignRole implements CommandInterface
{
    public function execute(array $args = [], array $data = [])
    {
        $formatArgs = $args['format'] ?? [];
        array_shift($formatArgs);
        $username = trim((string)($formatArgs['username'] ?? $args['username'] ?? ''));
        $userId   = trim((string)($formatArgs['user_id'] ?? $args['user_id'] ?? ''));
        $email    = trim((string)($formatArgs['email'] ?? $args['email'] ?? ''));
        $roleId   = trim((string)($formatArgs['role_id'] ?? $args['role_id'] ?? ''));
        $roleName = trim((string)($formatArgs['role'] ?? $args['role'] ?? ''));

        /** @var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);

        if ($username === '' && $userId === '' && $email === '') {
            $printer->error(__('请指定 --username=、--user_id= 或 --email= 之一'));
            $this->printRoleList($printer);
            return;
        }

        if ($roleId === '' && $roleName === '') {
            $printer->error(__('请指定 --role_id= 或 --role= 选择要分配的角色'));
            $this->printRoleList($printer);
            return;
        }

        $roleIdInt = $this->resolveRoleId($roleId, $roleName, $printer);
        if ($roleIdInt <= 0) {
            return;
        }

        /** @var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class);
        $user->reset();

        if ($userId !== '') {
            $user->load((int) $userId);
        } elseif ($username !== '') {
            $user->where(BackendUser::schema_fields_username, $username)->find()->fetch();
        } else {
            $user->where(BackendUser::schema_fields_email, $email)->find()->fetch();
        }

        if (!$user->getId()) {
            $printer->error(__('用户不存在'));
            return;
        }

        try {
            $user->assignRole($roleIdInt);
            /** @var Role $role */
            $role = ObjectManager::getInstance(Role::class)->load($roleIdInt);
            $roleLabel = $role->getId() ? $role->getRoleName() : (string) $roleIdInt;
            $printer->success(__('已为用户分配角色') . '：' . $user->getUsername() . ' → ' . $roleLabel . ' (user_id=' . $user->getId() . ', role_id=' . $roleIdInt . ')');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate') || str_contains($msg, 'UNIQUE')) {
                $printer->note(__('该用户已拥有该角色') . '：' . $user->getUsername());
                return;
            }
            $printer->error(__('分配失败') . '：' . $msg);
        }
    }

    private function resolveRoleId(string $roleId, string $roleName, Printing $printer): int
    {
        if ($roleId !== '' && is_numeric($roleId)) {
            /** @var Role $role */
            $role = ObjectManager::getInstance(Role::class)->load((int) $roleId);
            if ($role->getId()) {
                return (int) $roleId;
            }
            $printer->error(__('角色 ID %{id} 不存在', ['id' => $roleId]));
            return 0;
        }

        if ($roleName !== '') {
            /** @var Role $role */
            $role = ObjectManager::getInstance(Role::class);
            $role->where(Role::schema_fields_ROLE_NAME, $roleName)->find()->fetch();
            if ($role->getId()) {
                return $role->getId();
            }
            $printer->error(__('角色名「%{name}」不存在', ['name' => $roleName]));
            return 0;
        }

        return 0;
    }

    private function printRoleList(Printing $printer): void
    {
        /** @var Role $roleModel */
        $roleModel = ObjectManager::getInstance(Role::class);
        $roles = $roleModel->select()->fetch()->getItems();
        if (empty($roles)) {
            $printer->note(__('暂无可用角色，请先在后台创建角色'));
            return;
        }
        $printer->note(__('可用角色：'));
        foreach ($roles as $r) {
            $printer->printing('  --role_id=' . $r['role_id'] . ' 或 --role=' . $r['role_name']);
        }
    }

    public function tip(): string
    {
        return __('为指定后台用户分配所选角色。')
            . ' php bin/w user:assign-role --username=demo --role_id=2'
            . ' 或 --role=角色名';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--username=' => __('用户名'),
                '--user_id='  => __('用户ID'),
                '--email='    => __('邮箱'),
                '--role_id='  => __('角色ID'),
                '--role='     => __('角色名'),
                '-h, --help'  => __('显示帮助信息'),
            ],
            [],
            [
                __('分配超管')   => 'php bin/w user:assign-super-admin --username=admin',
                __('分配其他角色') => 'php bin/w user:assign-role --username=demo --role_id=2',
            ]
        );
    }
}
