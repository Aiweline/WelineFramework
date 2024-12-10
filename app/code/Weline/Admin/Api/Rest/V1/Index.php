<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;

class Index extends BackendRestController
{
    public function index(): bool|string
    {
        $data = ['name' => '后台rest接口！', 'params' => $this->request->getParams()];

        return $this->fetch($data, self::fetch_XML);
    }
}
