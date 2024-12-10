<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/8/21 14:35:26
 */

namespace Weline\Backend\Console\User\Reset;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Password implements CommandInterface
{

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $formatArgs = $args['format'] ?? [];
        array_shift($formatArgs);
        /**@var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);
        if (empty($formatArgs['email'])) {
            $printer->error(__('用户邮箱不能为空'));
            return;
        }
        if (empty($formatArgs['password'])) {
            $printer->error(__('用户密码不能为空'));
            return;
        }
        /**@var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class);
        $user->load('email', $formatArgs['email']);
        if(!$user->getId()) {
            $printer->error(__('用户不存在'));
            return;
        }
        $user->setPassword($formatArgs['password'])
            ->save();
        $printer->success(__('重置成功'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '重置用户密码。php bin/w user:reset:password --email=demo@123.com --password=123456';
    }
}