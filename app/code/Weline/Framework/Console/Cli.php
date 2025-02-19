<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Cli extends CliAbstract
{
    public const core_FRAMEWORK_NAMESPACE = Env::framework_name . '\\Framework';

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @throws ConsoleException
     * @throws Exception
     */
    public function run(): void
    {
        # 检测用户
        \Weline\Framework\App\Env::check_user();
        // 没有任何参数
        if (!isset($this->argv[0])) {
            $this->execute();
            exit();
        }
        $args = $this->parseArgs($this->argv);
        $command_class = $this->checkCommand($args);
        $data = $command_class['data'];
        ObjectManager::getInstance($command_class['class'])->execute($args, $data);
        $this->printer->printing("\n");
        $this->printer->note(__('执行命令：') . $command_class['command'] . ' ' . ($this->argv[1] ?? '')/*,$this->printer->colorize('CLI-System','red')*/);
    }

    function parseArgs(array $args): array
    {
        foreach ($args as $k => $arg) {
            if ($k == 0) {
                $args['command'] = $arg;
                continue;
            }
            if (is_string($k)) {
                continue;
            }
            if (str_contains($arg, '=')) {
                $arg = explode('=', $arg);
                $args[trim($arg[0], '-')] = $arg[1] ?? true;
                continue;
            }
            # 参数名
            if (str_starts_with($arg, '-')) {
                $argName = trim($arg, '-');
                $next = $args[$k + 1] ?? null;
                if (empty($next)) {
                    $args[$argName] = true;
                    $args[$arg] = true;
                    continue;
                }
                if (str_starts_with($next, '-')) {
                    $args[$arg] = true;
                    $args[$argName] = true;
                    $argName = null;
                }
            } elseif (!empty($argName)) {
                if (!isset($args[$argName])) {
                    $args[$argName] = $arg;
                } else {
                    if (is_array($args[$argName])) {
                        $args[$argName][] = $arg;
                    } else {
                        $args[$argName] = [$args[$argName], $arg];
                    }
                }
            }
        }
        return $args;
    }

    /**
     * @DESC         |简化推荐命令
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $commands
     *
     * @return array
     */
    private function recommendCommand(array $commands): array
    {
        // 新算法

        // 旧算法
        $arg0 = strtolower(trim($this->argv[0]));
        $input_command_arr = explode(':', $arg0);
        $recommendCommands = [];
        $matchCommand = [];
        foreach ($commands as $group => $command) {
            $keys = array_keys($command);
            foreach ($keys as $command_key) {
                // 匹配参考
                if (is_int(strpos($command_key, $arg0))) {
                    $matchCommand[$group][] = [$command_key => $command[$command_key]];
                }

                $command_key_arr = explode(':', $command_key);
                $k = 0;
                foreach ($input_command_arr as $input_key => $input_command_head) {
                    // 如果长度和首匹配都相同
                    if (count($command_key_arr) === count($input_command_arr)) {
                        if ($input_command_head) {
                            $input_str_pos = strpos($command_key_arr[$input_key], $input_command_head);
                            if (isset($command_key_arr[$input_key]) && !is_bool($input_str_pos) && $input_str_pos === 0) {
                                $k += 1;
                            }
                        }
                    }
                }
                if (count($input_command_arr) === $k) {
                    $matchCommand[$group][] = [$command_key => $command[$command_key]];
                }
                if ($k > 0) {
                    $recommendCommands[$group][] = [$command_key => $command[$command_key]];
                }
            }
        }
        return $matchCommand ?: $recommendCommands;
    }

    /**
     * @DESC         |检查命令
     *
     * 参数区：
     *
     * @param array $args
     * @return array
     */
    private function checkCommand(array $args): array
    {
        $arg0 = strtolower(trim($this->argv[0]));
        if ($arg0 === 'command:upgrade') {
            try {
                ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class)->execute();
            } catch (Exception $exception) {
                $this->printer->error($exception->getMessage());
                exit();
            }
        }
        $commands = Env::getCommands();
        if ($arg0 !== 'command:upgrade' && empty($commands)) {
            try {
                ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class)->execute();
            } catch (Exception $exception) {
                $this->printer->error($exception->getMessage());
                exit();
            }
//            exit($this->printer->error('命令系统异常！请完整执行（不能简写）更新模块命令后重试：php bin/w command:upgrade'));
        }

        // 检查完整命令
        foreach ($commands as $group => $group_commands) {
            if (isset($group_commands[$arg0]) && $command_data = $group_commands[$arg0]) {
                $command_class = $command_data['class'];
                return ['class' => $command_class, 'command' => $arg0, 'data' => $command_data];
            }
        }

        $recommendCommands = $this->recommendCommand($commands);
        $commands = [];
        foreach ($recommendCommands as $recommendCommand) {
            $commands = array_merge($commands, $recommendCommand);
        }
        if (count($commands) === 1 && $command = $commands[0]) {
            foreach ($command as $c => $data) {
                return ['class' => $data['class'], 'command' => $c, 'data' => $data];
            }
        }
        foreach ($recommendCommands as $key => &$command) {
            foreach ($command as $k => $item) {
                unset($command[$k]);
                $keys = array_keys($item);
                $command[array_shift($keys)] = array_pop($item);
            }
        }
        $this->printer->error('无效命令：' . $arg0, 'CLI');
        $this->printer->note('参考命令', '系统');
        $this->printer->printList($recommendCommands);
        exit();
    }
}
