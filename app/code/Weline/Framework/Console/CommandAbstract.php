<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\Register;

abstract class CommandAbstract implements CommandInterface
{
    use ParseModuleArgsTrait;

    protected Printing $printer;

    public function __init()
    {
        $this->printer = new Printing();
    }

    /**
     * @DESC         |方法描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $module_path
     * @param string $command
     *
     * @return string
     */
    protected function getCommandPath(string $module_path, string $command = ''): string
    {
        $command_array = explode(':', $command);
        foreach ($command_array as &$command) {
            $command = ucfirst($command);
        }
        $module_path = Register::composerNameConvertToNamespace($module_path);

        return $module_path . '\\' . self::dir . '\\' . implode('\\', $command_array);
    }

    /**
     * @DESC         |命令帮助信息
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array|string
     */
    public function help(): array|string
    {
        // 默认help实现，子类应该重写此方法提供详细帮助
        return $this->tip();
    }

    /**
     * @DESC         |命令提示
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string
    {
        return '命令';
    }
}
