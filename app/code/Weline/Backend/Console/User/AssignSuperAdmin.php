<?php
declare(strict_types=1);

/*
 * 为指定后台用户分配超管角色（role_id=1），使其符合「有角色」的登录条件。
 * 用法：php bin/w user:assign-superadmin --username=xxx 或 --user_id=1 或 --email=xxx@x.com
 */

namespace Weline\Backend\Console\User;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class AssignSuperAdmin implements CommandInterface
{
    private const ROLE_SUPER_ADMIN = 1;

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $formatArgs = $args['format'] ?? [];
        array_shift($formatArgs);
        $username = $formatArgs['username'] ?? $args['username'] ?? '';
        $userId   = $formatArgs['user_id'] ?? $args['user_id'] ?? '';
        $email    = $formatArgs['email'] ?? $args['email'] ?? '';

        /** @var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);

        if ($username === '' && $userId === '' && $email === '') {
            $printer->error(__('请指定 --username=、--user_id= 或 --email= 之一'));
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
            $user->assignRole(self::ROLE_SUPER_ADMIN);
            $printer->success(__('已为用户分配超管角色') . '：' . $user->getUsername() . ' (user_id=' . $user->getId() . ')');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate') || str_contains($msg, 'UNIQUE')) {
                $printer->note(__('该用户已是超管角色') . '：' . $user->getUsername());
                return;
            }
            $printer->error(__('分配失败') . '：' . $msg);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('为指定后台用户分配超管角色。')
            . ' php bin/w user:assign-superadmin --username=admin'
            . ' 或 --user_id=2 或 --email=admin@example.com';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--username=' => __('用户名'),
                '--user_id='  => __('用户ID'),
                '--email='    => __('邮箱'),
                '-h, --help'  => __('显示帮助信息'),
            ],
            [],
            []
        );
    }
}
