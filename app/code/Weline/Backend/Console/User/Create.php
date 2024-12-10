<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/8/2 16:20:07
 */

namespace Weline\Backend\Console\User;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Create implements CommandInterface
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
        if (empty($formatArgs['username'])) {
            $printer->error(__('用户名不能为空'));
            return;
        }
        if (empty($formatArgs['email'])) {
            $printer->error(__('邮箱不能为空'));
            return;
        }
        if (empty($formatArgs['password'])) {
            $printer->error(__('密码不能为空'));
            return;
        }
        # 检查账户是否存在
        /**@var BackendUser $userModel */
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user      = $userModel->reset()
            ->where('email', $formatArgs['email'], '=', 'or')
            ->where('username', $formatArgs['username'])
            ->find()
            ->fetchArray();
        if ($user) {
            $printer->error(__('用户已存在'));
            return;
        }
        try {
            $userId = $userModel->reset()
                ->setUsername($formatArgs['username'])
                ->setEmail($formatArgs['email'])
                ->setPassword($formatArgs['password'])
                ->save();
            if(!$userId){
                $printer->error(__('用户创建失败'));
                return;
            }
            $printer->success(__('用户创建成功'));
        }catch (\Exception $exception){
            $printer->error(__('用户创建失败：%s', $exception->getMessage()));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '创建后台用户。php bin/w user:create --username=demo --email=demo@aiweline.com --password=123456';
    }
}