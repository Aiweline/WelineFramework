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
use ReflectionClass;

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
     * 安装模式标志文件路径
     * 安装模式下会扫描所有模块的命令（不管是否激活），确保核心命令可用
     */
    public const INSTALL_MODE_FLAG = BP . 'var' . DS . 'process' . DS . 'command_install_mode.flag';
    
    /**
     * 设置安装模式
     * 安装模式下 command:upgrade 会扫描所有模块的命令，不仅仅是已激活的模块
     * 
     * @param bool $enabled 是否启用安装模式
     * @return void
     */
    public static function setInstallMode(bool $enabled): void
    {
        $flagDir = dirname(self::INSTALL_MODE_FLAG);
        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0755, true);
        }
        
        if ($enabled) {
            @file_put_contents(self::INSTALL_MODE_FLAG, date('Y-m-d H:i:s'));
        } else {
            @unlink(self::INSTALL_MODE_FLAG);
        }
    }
    
    /**
     * 检查是否处于安装模式
     * 
     * @return bool
     */
    public static function isInstallMode(): bool
    {
        return is_file(self::INSTALL_MODE_FLAG);
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

        // 检查是否处于安装模式
        $installMode = self::isInstallMode();
        if ($installMode) {
            $this->printer->note(__('安装模式：扫描所有模块命令（包括未激活的模块）'));
        }

        $commands = $this->scan($installMode);
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
     * @param bool $installMode 是否为安装模式（扫描所有模块，不仅仅是已激活的）
     * @return array
     */
    public function scan(bool $installMode = false): array
    {
        return $this->getDirFileCommand($installMode);
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
     * @param bool $installMode 是否为安装模式（扫描所有模块，不仅仅是已激活的）
     * @return array
     * @throws \ReflectionException
     */
    private function getDirFileCommand(bool $installMode = false): array
    {
        $commands = [];
        $processedClasses = [];
        $processedFiles = [];

        # 模组命令
        // 安装模式下扫描所有模块，正常模式下只扫描已激活的模块
        if ($installMode) {
            $active_modules = $this->scanAllModules();
        } else {
            $active_modules = Env::getInstance()->getActiveModules();
        }
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
                        // 跳过抽象类、trait、接口和静态类
                        if ($classRef->isAbstract() || $classRef->isTrait() || $classRef->isInterface()) {
                            continue;
                        }
                        // 检查是否为静态类（所有方法都是静态的，且没有公共构造函数）
                        if ($this->isStaticClass($declaredClass)) {
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
                // 跳过抽象类、trait、接口和静态类
                if ($classRef->isAbstract() || $classRef->isTrait() || $classRef->isInterface()) {
                    continue;
                }
                // 检查是否为静态类（所有方法都是静态的，且没有公共构造函数）
                if ($this->isStaticClass($class)) {
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
    
    /**
     * 扫描所有模块（不管是否激活）
     * 用于安装模式下收集所有模块的命令
     * 
     * @return array 模块列表，格式与 Env::getActiveModules() 相同
     */
    private function scanAllModules(): array
    {
        $modules = [];
        $codePath = BP . 'app' . DS . 'code' . DS;
        
        if (!is_dir($codePath)) {
            return $modules;
        }
        
        // 扫描 app/code 下的所有 Vendor/Module 目录
        $vendors = @scandir($codePath);
        if (!$vendors) {
            return $modules;
        }
        
        foreach ($vendors as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }
            $vendorPath = $codePath . $vendor . DS;
            if (!is_dir($vendorPath)) {
                continue;
            }
            
            $moduleDirs = @scandir($vendorPath);
            if (!$moduleDirs) {
                continue;
            }
            
            foreach ($moduleDirs as $moduleName) {
                if ($moduleName === '.' || $moduleName === '..') {
                    continue;
                }
                $modulePath = $vendorPath . $moduleName . DS;
                $registerFile = $modulePath . 'register.php';
                
                // 必须有 register.php 才是有效模块
                if (!is_file($registerFile)) {
                    continue;
                }
                
                $fullModuleName = $vendor . '_' . $moduleName;
                $modules[$fullModuleName] = [
                    'name' => $fullModuleName,
                    'base_path' => $modulePath,
                    'namespace_path' => $vendor . '\\' . $moduleName,
                    'version' => '0.0.0',
                    'description' => '',
                ];
            }
        }
        
        return $modules;
    }
    
    /**
     * 检测类是否为静态类（所有方法都是静态的，且没有可实例化的构造函数）
     *
     * @param string $class 类名
     * @return bool
     */
    private function isStaticClass(string $class): bool
    {
        try {
            $refClass = new ReflectionClass($class);
            
            // 检查是否有公共构造函数
            $constructor = $refClass->getConstructor();
            if ($constructor && $constructor->isPublic()) {
                // 有公共构造函数，不是静态类
                return false;
            }
            
            // 检查是否有 getInstance 等工厂方法（单例模式）
            if ($refClass->hasMethod('getInstance') && $refClass->getMethod('getInstance')->isStatic()) {
                // 有静态工厂方法，不是纯静态类
                return false;
            }
            
            // 获取所有公共方法
            $methods = $refClass->getMethods(\ReflectionMethod::IS_PUBLIC);
            
            // 如果没有公共方法，可能是静态类
            if (empty($methods)) {
                return true;
            }
            
            // 检查是否所有公共方法都是静态的
            foreach ($methods as $method) {
                // 跳过构造函数和魔术方法
                if (in_array($method->getName(), ['__construct', '__destruct', '__clone', '__wakeup', '__sleep'])) {
                    continue;
                }
                
                // 如果有非静态的公共方法，不是静态类
                if (!$method->isStatic()) {
                    return false;
                }
            }
            
            // 所有公共方法都是静态的，且没有公共构造函数，判定为静态类
            return true;
        } catch (\ReflectionException $e) {
            // 反射失败，假设不是静态类
            return false;
        }
    }
}
