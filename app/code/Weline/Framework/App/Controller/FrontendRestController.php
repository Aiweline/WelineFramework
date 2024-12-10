<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;

class FrontendRestController extends AbstractRestController
{
    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $msg
     * @param bool   $data
     * @param int    $code
     */
    public function errorXml(string $msg = '错误！', mixed $data = false, int $code = 400)
    {
        die($this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]));
    }
}
