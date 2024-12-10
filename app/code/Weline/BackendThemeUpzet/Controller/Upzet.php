<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/15 11:42:59
 */

namespace Weline\BackendThemeUpzet\Controller;

class Upzet extends \Weline\Framework\App\Controller\BackendController
{
    function __construct()
    {
        $this->getTemplate()->setFileExt('html');
    }

    function index()
    {
        $template = $this->request->getRule('template');
        return $this->fetch($template);
    }

    function help()
    {
        $template = $this->request->getRule('template');
        return $this->fetch('help/'.$template);
    }
}