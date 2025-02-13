<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;

abstract class CliAbstract implements CommandInterface
{
    public const RETURN_FAILURE = '异常退出！';

    /**
     * @var array
     */
    protected array $argv;

    /**
     * @var string
     */
    protected string $msg;

    /**
     * @var string
     */
    protected mixed $command_file;

    /**
     * @var \ReflectionClass
     */
    protected \ReflectionClass $reflection_class;

    public \Weline\Framework\Output\Cli\Printing $printer;

    /**
     * Cli 初始函数...
     *
     * @param string $msg
     * @param array $argv
     *
     * @throws \ReflectionException
     */
    public function __construct(string $msg, array $argv)
    {
        $this->command_file     = array_shift($argv);
        $this->argv             = $argv;
        $this->msg              = $msg;
        $this->reflection_class = new \ReflectionClass($this);
        $this->printer          = new \Weline\Framework\Output\Cli\Printing();
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
        return 'CLI ' . self::version;
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
     * @param $command
     *
     * @return string
     */
    protected function getCommandPath($command = null): string
    {
        $command_array = explode(':', $command);
        foreach ($command_array as &$command) {
            $command = ucfirst($command);
        }

        return '\\' . self::dir . '\\' . implode('\\', $command_array);
    }

    /**
     * @DESC         |读取默认命令提示
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array|null $args
     *
     * @return mixed|void
     * @throws \Weline\Framework\App\Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        $commands = $this->getCommandList();

        $this->printer->printList($commands);
    }

    /**
     * @DESC         |获取命令列表
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array|string[][]
     * @throws \Weline\Framework\App\Exception
     */
    public function getCommandList(): array
    {
        // 扫描所有命令
        $commands = Env::getCommands();
        if (empty($commands)) {
            exec('php ' . BP . 'bin/w command:upgrade', $result);
            return Env::getCommands();
        }
        return $commands;
    }
}
