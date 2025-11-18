<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;

interface SessionInterface
{
    public function start(string $session_id);

    public function getData(string $name);

    public function setData(string $name, mixed $value);

    public function isLogin();

    public function login(Model $user);

    public function getLoginUser(string $model): ?AbstractModel;

    public function getLoginUsername();

    public function getLoginUserID();

    public function logout();

    public function getOriginSession();

    public function destroy();

    public function delete(string $name);
}
