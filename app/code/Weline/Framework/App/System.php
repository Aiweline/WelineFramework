<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Path\Directory;

class System
{
    /**
     * @DESC         |执行linux命令兼容win
     *
     * 参数区：
     *
     * @param string $linux_command
     * @param bool $process_win_to_linux
     *
     * @return array|string
     * @throws \Weline\Framework\App\Exception
     */
    public function exec(string $linux_command, bool $process_win_to_linux = true): array|string
    {
        if (IS_WIN && $process_win_to_linux) {
            // 删除
            if (
                (is_int(strpos($linux_command, 'rm ')) || is_int(strpos($linux_command, 'cp '))) &&
                (is_int(strpos($linux_command, ' -rf')) || is_int(strpos($linux_command, ' -fr ')))
            ) {
                $linux_command = str_replace('rm ', 'rd ', $linux_command);
                $linux_command = str_replace(' -rf ', ' -r  -f ', $linux_command);
                $linux_command = str_replace(' -rf', ' -r  -f ', $linux_command);
                $linux_command = str_replace(' -fr ', ' -r  -f ', $linux_command);
            }
            $linux_to_win = [
                'rm ' => ' del ',
                ' -f ' => ' /S/Q ',
                'cp ' => ' xcopy ',
                ' -r ' => '  ',
            ];


            foreach ($linux_to_win as $key => $item) {
                $linux_command = str_replace($key, $item, $linux_command);
            }
            if (is_int(strpos($linux_command, 'xcopy'))) {
                $linux_command = str_replace('/S/Q', '/S/Q/Y', $linux_command);
            }
        }
        # 检测函数是否解禁
        if (!function_exists('exec')) {
            throw new Exception(__(' exec() 函数需要解禁: 请到 php.ini 中找到 disable_function 删除 exec '));
        }

        exec($linux_command, $output, $return_var);

        return ['command' => $linux_command, 'output' => $output, 'return_vars' => $return_var];
    }

    /**
     * @DESC         |执行win命令
     *
     * 参数区：
     *
     * @param string $win_command
     *
     * @return array|string
     * @throws \Weline\Framework\App\Exception
     */
    public function win_exec(string $win_command): array|string
    {
        # 检测函数是否解禁
        if (!function_exists('exec')) {
            throw new Exception(__(' exec() 函数需要解禁: 请到 php.ini 中找到 disable_function 删除 exec '));
        }

        exec($win_command, $output, $return_var);

        return ['command' => $win_command, 'output' => $output, 'return_vars' => $return_var];
    }

    /**
     * @DESC         |执行win命令
     *
     * 参数区：
     *
     * @param string $linux_command
     *
     * @return array|string
     * @throws \Weline\Framework\App\Exception
     */
    public function linux_exec(string $linux_command): array|string
    {
        # 检测函数是否解禁
        if (!function_exists('exec')) {
            throw new Exception(__(' exec() 函数需要解禁: 请到 php.ini 中找到 disable_function 删除 exec '));
        }

        exec($linux_command, $output, $return_var);

        return ['command' => $linux_command, 'output' => $output, 'return_vars' => $return_var];
    }

    public function input(): bool|string
    {
        return fread(STDIN, 1024);
    }

    /**
     * @DESC         |获得目录对象
     *
     * 参数区：
     *
     * @return Directory
     * @throws \ReflectionException
     */
    public function getDirectoryObject(): Directory
    {
        return ObjectManager::getInstance(Directory::class);
    }

    public function getLocalIp(): string
    {
        return getHostByName(getHostName());
    }
}
