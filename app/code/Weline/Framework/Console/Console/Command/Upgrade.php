<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Command;

use Weline\Framework\App\System;
use Weline\Framework\App\Env;
use Weline\Framework\Console\Command;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scan;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Upgrade extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;
    /**
     * @var Scan
     */
    private Scan $scan;

    /**
     * @var Command
     */
    private Command $command;

    public function __construct(
        Printing $printer,
        Command  $command,
        Scan     $scan,
        System   $system
    )
    {
        $this->printer = $printer;
        $this->system = $system;
        $this->scan = $scan;
        $this->command = $command;
    }

    /**
     * @DESC         |命令描述
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
        return '更新命令';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }

    /**
     * @DESC         |执行
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     *
     * @return mixed|void
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        // 删除命令文件
        if (is_file(Env::path_COMMANDS_FILE)) {
            $data = $this->system->exec('rm ' . Env::path_COMMANDS_FILE);
            if ($data) {
                $this->printer->printList($data);
            }
        }

        $commands = $this->scan();
        // 注册命令别名
        $commands = $this->registerAliases($commands);
        /**@var $file \Weline\Framework\System\File\Io\File */
        $file = ObjectManager::getInstance(\Weline\Framework\System\File\Io\File::class);
        $file->open(Env::path_COMMANDS_FILE, $file::mode_w_add);
        $text = '<?php return ' . w_var_export($commands, true) . ';';
        $file->write($text);
        $file->close();
        $this->printer->printList($commands);
        $this->printer->success(__('所有命令已更新！'));
    }

    /**
     * @DESC         |扫描命令
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array
     */
    public function scan(): array
    {
        return $this->getDirFileCommand();
    }

    /**
     * @DESC         |文件转换命令
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array
     * @throws \ReflectionException
     */
    private function getDirFileCommand(): array
    {
        $commands = [];
        $processedClasses = [];
        $processedFiles = [];

        # 模组命令
        $active_modules = Env::getInstance()->getActiveModules();
        unset($active_modules['Weline_Framework']);
        foreach ($active_modules as $module_name => $module) {
            $pattern = $module['base_path'] . 'Console' . DS . '*';
            $files = [];
            $this->scan->globFile($pattern, $files, '.php', $module['base_path'], '', true, true);
            foreach ($files as $file) {
                $class = $module['namespace_path'] . '\\' . $file;
                $filePath = $module['base_path'] . str_replace('\\', DS, $file) . '.php';
                $fileReal = is_file($filePath) ? realpath($filePath) : $filePath;
                if ($fileReal && isset($processedFiles[$fileReal])) {
                    continue;
                }
                // 直接按文件加载一次，避免命名空间不一致导致的重复加载
                $before = get_declared_classes();
                if ($fileReal && is_file($fileReal)) {
                    include_once $fileReal;
                    $processedFiles[$fileReal] = true;
                }
                $after = get_declared_classes();
                $newClasses = array_diff($after, $before);
                // 逐个检查新声明的类
                foreach ($newClasses as $declaredClass) {
                    if (isset($processedClasses[$declaredClass])) {
                        continue;
                    }
                    try {
                        $classRef = ObjectManager::getReflectionInstance($declaredClass);
                        if ($classRef->isAbstract()) {
                            continue;
                        }
                        $command_class = ObjectManager::getInstance($declaredClass);
                        if ($command_class instanceof CommandInterface) {
                            $file_array = explode('\\', $file);
                            array_shift($file_array);
                            $fileKey = implode(':', $file_array);
                            # 处理大写字母转化成-开头
                            $fileKey = w_split_by_capital($fileKey);
                            $file_str = '';
                            $pre_end_with = '';
                            $pre_is_one = false;
                            foreach ($fileKey as $key => &$item) {
                                if (1 == strlen($item)) {
                                    $file_str .= $item;
                                    $pre_is_one = true;
                                    $pre_end_with = $item[strlen($item) - 1];
                                    continue;
                                }
                                if ($pre_is_one) {
                                    $file_str .= $item;
                                    $pre_end_with = $item[strlen($item) - 1];
                                    $pre_is_one = false;
                                    continue;
                                }
                                if ($pre_end_with and ':' === $pre_end_with) {
                                    $file_str .= $item;
                                    $pre_end_with = $item[strlen($item) - 1];
                                    continue;
                                }
                                $pre_end_with = $item[strlen($item) - 1];
                                if (':' === $pre_end_with) {
                                    $file_str .= $item;
                                    continue;
                                }
                                if ($key !== 0) {
                                    $file_str .= '-' . $item;
                                } else {
                                    $file_str .= $item;
                                }
                            }
                            $command = str_replace('\\', ':', strtolower($file_str));
                            array_pop($file_array);
                            $command_prefix = strtolower(implode(':', $file_array));
                            $commands[$command_prefix . '#' . $module_name][$command] = [
                                'tip' => $command_class->tip(),
                                'class' => $declaredClass,
                                'type' => 'module',
                                'module' => $module['name']
                            ];
                            // 处理命令别名（从类常量读取）
                            $aliases = [];
                            if (defined($declaredClass . '::ALIASES')) {
                                $aliases = $declaredClass::ALIASES;
                            }
                            if (!empty($aliases) && is_array($aliases)) {
                                foreach ($aliases as $alias) {
                                    if (is_string($alias) && !empty($alias)) {
                                        $commands[$command_prefix . '#' . $module_name][$alias] = [
                                            'tip' => $command_class->tip(),
                                            'class' => $declaredClass,
                                            'type' => 'module',
                                            'module' => $module['name']
                                        ];
                                    }
                                }
                            }
                            $processedClasses[$declaredClass] = true;
                        }
                    } catch (\Throwable $exception) {
                        if (DEV && CLI) {
                            $this->printer->warning($exception->getMessage());
                        }
                        continue;
                    }
                }
            }
        }
        # 框架命令
        $framework_files = [];
        $this->scan->globFile(
            Env::framework_path . '*' . DS . 'Console' . DS . '*',
            $framework_files,
            '.php',
            Env::framework_path,
            Env::framework_name . '\\Framework\\',
            true,
            true
        );
        $this->scan->globFile(
            Env::framework_code_path . '*' . DS . 'Console' . DS . '*',
            $framework_files,
            '.php',
            Env::framework_code_path,
            'Weline\\Framework\\',
            true,
            true
        );
        foreach ($framework_files as $class) {
            // 排除非框架系统命令类
            // 框架类以类名去重即可
            if (isset($processedClasses[$class])) {
                continue;
            }
            try {
                $classRef = ObjectManager::getReflectionInstance($class);
                if ($classRef->isAbstract()) {
                    continue;
                }
                $command_class = ObjectManager::getInstance($class);
                if ($command_class instanceof CommandInterface) {
                    $class_array = explode('\\', $class);
                    array_shift($class_array);
                    array_shift($class_array);
                    $framework_module = array_shift($class_array);
                    array_shift($class_array);
                    $command = implode(':', $class_array);
                    $command = str_replace('\\', ':', strtolower($command));
                    array_pop($class_array);
                    $command_prefix = strtolower(implode(':', $class_array));
                    $commands[$command_prefix . '#Weline_Framework_' . $framework_module][$command] = [
                        'tip' => $command_class->tip(),
                        'class' => $class,
                        'type' => 'framework',
                        'module' => 'Weline_Framework'
                    ];
                    // 处理命令别名（从类常量读取）
                    $aliases = [];
                    if (defined($class . '::ALIASES')) {
                        $aliases = $class::ALIASES;
                    }
                    if (!empty($aliases) && is_array($aliases)) {
                        foreach ($aliases as $alias) {
                            if (is_string($alias) && !empty($alias)) {
                                $commands[$command_prefix . '#Weline_Framework_' . $framework_module][$alias] = [
                                    'tip' => $command_class->tip(),
                                    'class' => $class,
                                    'type' => 'framework',
                                    'module' => 'Weline_Framework'
                                ];
                            }
                        }
                    }
                    $processedClasses[$class] = true;
                } else {
                    if (DEV && CLI) {
                        $this->printer->warning(__('命令类：%{1} 必须继承：%{2}', [$class, CommandInterface::class]));
                    }
                }
            } catch (\Throwable $exception) {
                // 异常的类不加入命令
                if (DEV && CLI) {
                    $this->printer->warning($exception->getMessage());
                }
            }
        }

        return $commands;
    }

    /**
     * @DESC         |注册命令别名
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 扫描所有命令，如果命令类实现了 aliases() 方法，则注册别名
     *
     * 参数区：
     *
     * @param array $commands 命令数组
     * @return array 返回包含别名的命令数组
     */
    private function registerAliases(array $commands): array
    {
        foreach ($commands as $group => $group_commands) {
            foreach ($group_commands as $command => $command_data) {
                if (!isset($command_data['class'])) {
                    continue;
                }
                
                try {
                    $command_class = ObjectManager::getInstance($command_data['class']);
                    if ($command_class instanceof CommandInterface) {
                        // 检查命令类是否实现了 aliases() 方法
                        if (method_exists($command_class, 'aliases')) {
                            $aliases = $command_class->aliases();
                            if (is_array($aliases) && !empty($aliases)) {
                                // 为每个别名注册命令
                                foreach ($aliases as $alias) {
                                    if (is_string($alias) && !empty($alias)) {
                                        // 确保别名不存在，避免覆盖已有命令
                                        if (!isset($commands[$group][$alias])) {
                                            $commands[$group][$alias] = $command_data;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $exception) {
                    // 如果无法实例化命令类，跳过别名注册
                    if (DEV && CLI) {
                        $this->printer->warning(__('无法注册命令别名：%{1} - %{2}', [$command_data['class'], $exception->getMessage()]));
                    }
                    continue;
                }
            }
        }
        
        return $commands;
    }
}
