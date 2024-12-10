<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Register;

use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\RegisterInterface;

class Installer implements RegisterInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * Installer 初始函数...
     *
     * @param Printing $printing
     */
    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @DESC         |注册主题
     *
     * 参数区：
     *
     * @param string       $type
     * @param string       $module_name
     * @param array|string $param
     * @param string       $version
     * @param string       $description
     */
    public function register(string $type, string $module_name, array|string $param, string $version = '', string $description = ''): mixed
    {
        // 参数检查
        $this->printing->printing(__('语言包(%1)：%2 已安装。', [$module_name, $param]));
        return '';
    }
}
