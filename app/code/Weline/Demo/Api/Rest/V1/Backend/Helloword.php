<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Demo\Api\Rest\V1\Backend;

use Weline\Framework\App\Controller\BackendRestController;

class Helloword extends BackendRestController
{
    public function index()
    {
        return $this->fetchJson([
            'code' => 200,
            'msg' => 'success',
            'data' => ['message' => 'Hello from Helloword']
        ]);
    }
}