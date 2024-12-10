<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Session;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Database\AbstractModel;

class BackendSession extends \Weline\Framework\App\Session\BackendSession
{
    public function loginUser(BackendUser $user): static
    {
        return parent::login($user->getUsername(), $user->getId());
    }

    public function getLoginUser(string $model = BackendUser::class): AbstractModel|BackendUser
    {
        return parent::getLoginUser($model);
    }
}
