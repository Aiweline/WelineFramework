<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Model;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\Setup\Db\ModelSetup;

class Rebuild extends CommandAbstract
{
    /**
     * @var ModelManager
     */
    private ModelManager $modelManager;
    
    /**
     * @var Handle
     */
    private Handle $moduleHandle;

    public function __construct(
        Printing $printer,
        ModelManager $modelManager,
        Handle $moduleHandle
    )
    {
        $this->printer = $printer;
        $this->modelManager = $modelManager;
        $this->moduleHandle = $moduleHandle;
    }

    /**
     * @DESC         |重建模型数据表
     *
     * 参数区：
     * @param array $args ['m' => '模块名', 'name' => '模型名']
     * @param array $data
     * @return mixed|void
     * @throws \Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        // 仅允许在开发模式下运行
        if (!DEV) {
            $this->printer->error('');
            $this->printer->error('═══════════════════════════════════════════════════════════════');
            $this->printer->error(__('  ⚠️  安全警告：此命令仅允许在开发环境中运行！'));
            $this->printer->error('═══════════════════════════════════════════════════════════════');
            $this->printer->error('');
            $this->printer->warning(__('此命令会删除并重建数据表，可能导致数据丢失！'));
            $this->printer->warning(__('为保护生产环境数据安全，此命令已被禁用。'));
            $this->printer->error('');
            $this->printer->note(__('如需在开发环境中使用，请在 .env 文件中设置：'));
            $this->printer->note(__('  dev=true'));
            $this->printer->error('');
            return;
        }
        
        // 若传入了模型文件路径，直接解析并执行
        if ($fileContext = $this->tryResolveFromFileArg($args, $data)) {
            $moduleName = $fileContext['module'];
            $modelClass = $fileContext['class'];
            $modules = $this->moduleHandle->getModules();
            if (!isset($modules[$moduleName])) {
                $this->printer->error(__('错误：模块 %{1} 不存在！', [$moduleName]));
                return;
            }
            $module = new Module($modules[$moduleName]);
            // 直接用完整类名重建
            $this->rebuildModel($module, $modelClass);
            return;
        }

        // 支持 --module/-m 参数
        $moduleName = $args['module'] ?? $args['m'] ?? '';
        // 支持 --name/-n 参数
        $modelName = $args['name'] ?? $args['n'] ?? '';
        
        // 确保 moduleName 是字符串（如果传入的是数组，取第一个元素）
        if (is_array($moduleName)) {
            $moduleName = reset($moduleName) ?: '';
        }
        // 确保 modelName 是字符串（如果传入的是数组，取第一个元素）
        if (is_array($modelName)) {
            $modelName = reset($modelName) ?: '';
        }
        
        // 从位置参数中提取模块名和模型名
        $positionalArgs = [];
        if (!empty($data)) {
            foreach ($data as $value) {
                if (is_string($value) && !empty($value)) {
                    $positionalArgs[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $v) {
                        if (is_string($v) && !empty($v)) {
                            $positionalArgs[] = $v;
                        }
                    }
                }
            }
        }
        
        // 如果模块名为空，尝试从位置参数获取
        if (empty($moduleName) && !empty($positionalArgs)) {
            $moduleName = $positionalArgs[0];
            // 如果有第二个参数，作为模型名
            if (isset($positionalArgs[1])) {
                $modelName = $positionalArgs[1];
            }
        } elseif (!empty($moduleName) && empty($modelName) && !empty($positionalArgs)) {
            // 如果模块名已指定但模型名为空，从位置参数获取模型名
            $modelName = $positionalArgs[0];
        }

        // 如果没有指定模块名，显示帮助
        if (empty($moduleName) || !is_string($moduleName)) {
            $this->printer->error(__('错误：必须指定模块名！'));
            $this->printer->note(__('使用方法：php bin/w model:rebuild -m <模块名> -n <模型名>'));
            return;
        }

        // 获取所有模块
        $modules = $this->moduleHandle->getModules();
        
        // 查找指定的模块（确保 $moduleName 是字符串且 $modules 是数组）
        if (!is_array($modules) || !is_string($moduleName) || !isset($modules[$moduleName])) {
            $this->printer->error(__('错误：模块 %{1} 不存在！', [$moduleName]));
            $this->showAvailableModules($modules);
            return;
        }

        $module = new Module($modules[$moduleName]);
        
        // 如果没有指定模型名，搜索推荐
        if (empty($modelName)) {
            $this->searchAndRecommendModels($module);
            return;
        }

        // 查找并重建指定的模型
        $this->rebuildModel($module, $modelName);
    }

    /**
     * 解析命令入参中的文件路径（例如：app\\code\\Weline\\Ai\\Model\\AiModel.php）
     * 返回 ['module' => 模块名, 'class' => 模型完整类名] 或 null
     */
    private function tryResolveFromFileArg(array $args, array $data = []): ?array
    {
        $candidate = null;
        $candidates = [];
        foreach ([$args, $data] as $bag) {
            foreach ($bag as $key => $value) {
                if (is_string($value)) {
                    $candidates[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $v) {
                        if (is_string($v)) {
                            $candidates[] = $v;
                        }
                    }
                }
            }
        }
        foreach ($candidates as $v) {
            if (str_ends_with($v, '.php')) {
                $candidate = $v;
                break;
            }
        }
        if (!$candidate) {
            return null;
        }

        // 归一化为绝对路径
        $path = str_replace(['/', '\\'], DS, $candidate);
        if (!str_starts_with($path, 'app' . DS)) {
            // 允许直接传绝对路径
            if (!is_file($path)) {
                // 尝试拼接项目根目录
                $path = rtrim(BP, DS) . DS . ltrim($path, DS);
            }
        } else {
            $path = rtrim(BP, DS) . DS . $path;
        }
        if (!is_file($path)) {
            return null;
        }

        // 推导完整类名：去掉 app\\code\\ 前缀，替换分隔符为命名空间反斜杠，去除 .php
        $relative = str_replace(rtrim(BP, DS) . DS, '', $path);
        $relative = ltrim($relative, DS);
        $prefix = 'app' . DS . 'code' . DS;
        if (!str_starts_with($relative, $prefix)) {
            return null;
        }
        $nsPart = substr($relative, strlen($prefix));
        $nsPart = str_replace(DS, '\\', $nsPart);
        if (substr($nsPart, -4) === '.php') {
            $nsPart = substr($nsPart, 0, -4);
        }
        $fqcn = $nsPart;

        // 定位所属模块
        $modules = $this->moduleHandle->getModules();
        $moduleName = null;
        foreach ($modules as $name => $meta) {
            $base = $meta['base_path'] ?? '';
            if ($base && str_starts_with($path, rtrim($base, DS) . DS)) {
                $moduleName = $name;
                break;
            }
        }
        if (!$moduleName) {
            // 根据命名空间头两个段推断模块名（Vendor_Module）
            $parts = explode('\\', $fqcn);
            if (count($parts) >= 2) {
                $guess = $parts[0] . '_' . $parts[1];
                if (isset($modules[$guess])) {
                    $moduleName = $guess;
                }
            }
        }
        if (!$moduleName) {
            return null;
        }

        // 尝试自动加载类（防止 class_exists 失败）
        if (!class_exists($fqcn) && is_file($path)) {
            require_once $path;
        }
        // 仅当该类存在且为 Model 子类时，才按文件直跑
        if (!class_exists($fqcn)) {
            return null;
        }
        try {
            $ref = new \ReflectionClass($fqcn);
            if (!$ref->isSubclassOf(\Weline\Framework\Database\Model::class)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'module' => $moduleName,
            'class' => $fqcn,
        ];
    }

    /**
     * 搜索并推荐模型
     */
    private function searchAndRecommendModels(Module $module): void
    {
        $modulePath = $module->getData('base_path');
        $modelPath = $modulePath . DS . 'Model';
        
        if (!is_dir($modelPath)) {
            $this->printer->warning(__('模块 %{1} 没有 Model 目录', [$module->getName()]));
            return;
        }

        $this->printer->setup(__('搜索模块 %{1} 中的模型...', [$module->getName()]));
        $this->printer->note('');
        
        // 递归扫描所有 PHP 文件
        // 模块名就是命名空间。
        $namespace = str_replace('_', '\\', $module->getName()) . '\\Model';
        $models = $this->scanModels($modelPath, $namespace);
        
        if (empty($models)) {
            $this->printer->warning(__('未找到任何模型'));
            return;
        }

        $this->printer->success(__('找到 %{1} 个模型：', [count($models)]));
        $this->printer->note('');
        
        $namespace = str_replace('_', '\\', $module->getName()) . '\\Model\\';
        foreach ($models as $index => $model) {
            $displayName = str_replace($namespace, '', $model['class']);
            $this->printer->note(sprintf('%3d. %-40s [%s]', 
                $index + 1, 
                $displayName,
                $model['file']
            ));
        }
        
        $this->printer->note('');
        $this->printer->setup(__('使用方法：php bin/w model:rebuild -m %{1} -n <模型名>', [$module->getName()]));
        $this->printer->note(__('  简写形式：-n Page'));
        $this->printer->note(__('  完整路径：-n Page/LocalDescription'));
    }

    /**
     * 递归扫描模型文件
     */
    private function scanModels(string $dir, string $namespace): array
    {
        $models = [];
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $fullPath = $dir . DS . $file;
            
            if (is_dir($fullPath)) {
                // 递归扫描子目录
                $subNamespace = $namespace . '\\' . $file;
                $subModels = $this->scanModels($fullPath, $subNamespace);
                $models = array_merge($models, $subModels);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $className = pathinfo($file, PATHINFO_FILENAME);
                $fullClassName = $namespace . '\\' . $className;
                
                // 检查类是否存在且是 Model 的子类
                if (class_exists($fullClassName)) {
                    $reflection = new \ReflectionClass($fullClassName);
                    if ($reflection->isSubclassOf(\Weline\Framework\Database\Model::class)) {
                        $models[] = [
                            'class' => $fullClassName,
                            'file' => str_replace([BP, DS . DS], ['', DS], $fullPath),
                            'name' => $className
                        ];
                    }
                }
            }
        }
        
        return $models;
    }

    /**
     * 重建指定的模型
     */
    private function rebuildModel(Module $module, string $modelName): void
    {
        // 构建完整的类名
        $modelClass = $this->findModelClass($module, $modelName);
        
        if (!$modelClass) {
            $this->printer->error(__('错误：未找到模型 %{1}', [$modelName]));
            $this->printer->note(__('正在搜索可能的模型...'));
            $this->searchAndRecommendModels($module);
            return;
        }

        $this->printer->setup(__('准备重建模型：%{1}', [$modelClass]));
        
        // 检查类是否存在
        if (!class_exists($modelClass)) {
            $this->printer->error(__('错误：类 %{1} 不存在！', [$modelClass]));
            return;
        }

        // 检查是否是 Model 的子类
        $reflection = new \ReflectionClass($modelClass);
        if (!$reflection->isSubclassOf(\Weline\Framework\Database\Model::class)) {
            $this->printer->error(__('错误：类 %{1} 不是 Model 的子类！', [$modelClass]));
            return;
        }

        // 创建 Setup Context
        $setup_context = ObjectManager::make(SetupContext::class, [
            'module_name' => $module->getName(),
            'module_version' => $module->getVersion(),
            'module_description' => $module->getDescription()
        ], '__construct');

        try {
            // 跳过静态类、抽象类、trait 和接口
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->isAbstract() || $reflection->isTrait() || $reflection->isInterface()) {
                    throw new \Exception(__('模型 %{1} 是抽象类、trait 或接口，无法重建', [$modelClass]));
                }
                if (ObjectManager::isStaticClass($modelClass)) {
                    throw new \Exception(__('模型 %{1} 是静态类，无法重建', [$modelClass]));
                }
            }
            
            // 创建模型实例
            $modelInstance = ObjectManager::getInstance($modelClass);
            
            // 创建 ModelSetup 实例
            $modelSetup = ObjectManager::getInstance(ModelSetup::class);
            $modelSetup->putModel($modelInstance);

            $this->printer->warning(__('步骤 1/2：删除旧表...'));
            
            // 删除表
            if ($modelSetup->tableExist()) {
                $modelSetup->dropTable();
                $this->printer->success(__('  ✓ 表 %{1} 已删除', [$modelSetup->getTable()]));
            } else {
                $this->printer->note(__('  - 表不存在，跳过删除'));
            }

            $this->printer->warning(__('步骤 2/2：重建表...'));
            
            // 调用 install 方法重建表
            if (method_exists($modelInstance, 'install')) {
                $modelInstance->install($modelSetup, $setup_context);
                $this->printer->success(__('  ✓ 表重建成功'));
                $this->printer->success(__(''));
                $this->printer->success(__('【Model重建】：%{1}', [$modelClass]));
                $this->printer->note(__('  表名：%{1}', [$modelSetup->getTable()]));
            } else {
                $this->printer->error(__('错误：模型 %{1} 没有 install 方法！', [$modelClass]));
            }

        } catch (\Exception $exception) {
            $this->printer->error(__('重建失败！'));
            $this->printer->error(__('错误信息：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->printer->error(__('堆栈跟踪：'));
                $this->printer->error($exception->getTraceAsString());
            }
        }
    }

    /**
     * 查找模型类
     * 支持简写（Page）和完整路径（Page/LocalDescription）
     */
    private function findModelClass(Module $module, string $modelName): ?string
    {
        // 已经是完整类名
        if (class_exists($modelName)) {
            return $modelName;
        }
        // 模块名就是命名空间。
        $namespace = str_replace('_', '\\', $module->getName()) . '\\Model';
        
        // 移除开头和结尾的反斜杠
        $modelName = trim($modelName, '\\/');
        
        // 将路径分隔符统一为命名空间分隔符
        $modelName = str_replace(['/', '\\'], '\\', $modelName);
        
        // 构建完整类名
        $fullClassName = $namespace . '\\' . $modelName;
        
        // 如果类存在，直接返回
        if (class_exists($fullClassName)) {
            return $fullClassName;
        }

        // 尝试在所有子目录中搜索
        $modulePath = $module->getData('base_path') . DS . 'Model';
        $models = $this->scanModels($modulePath, $namespace);
        
        // 精确匹配类名
        foreach ($models as $model) {
            if ($model['name'] === $modelName) {
                return $model['class'];
            }
        }
        
        // 模糊匹配（不区分大小写）
        $modelNameLower = strtolower($modelName);
        foreach ($models as $model) {
            if (strtolower($model['name']) === $modelNameLower) {
                return $model['class'];
            }
            
            // 匹配完整路径
            $classPath = str_replace($namespace . '\\', '', $model['class']);
            if (strtolower($classPath) === $modelNameLower) {
                return $model['class'];
            }
        }
        
        return null;
    }

    /**
     * 显示可用的模块列表
     */
    private function showAvailableModules(array $modules): void
    {
        $this->printer->note(__('可用的模块列表：'));
        $this->printer->note('');
        
        $index = 1;
        foreach ($modules as $moduleName => $module) {
            $this->printer->note(sprintf('%3d. %s', $index++, $moduleName));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '重建模型数据表（删除并重新创建）⚠️  仅限开发环境';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'model:rebuild',
            '重建模型数据表（删除旧表并重新创建）⚠️  仅限开发环境',
            [
                '-m, --module=<模块名>' => '指定模块名（必填）',
                '-n, --name=<模型名>' => '指定模型名（可选，不填则显示模块中所有可用模型）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '⚠️  安全警告：',
                '  此命令会删除并重建数据表，仅允许在开发环境（DEV=true）中运行。',
                '  在生产环境中运行会导致数据丢失！',
                '',
                '模型名支持多种格式：',
                '  - 简写：Page',
                '  - 完整路径：Page/LocalDescription',
                '  - 命名空间：Page\\LocalDescription',
            ],
            [
                '查看模块中所有模型' => 'php bin/w model:rebuild -m Weline_Demo',
                '重建指定模型（简写）' => 'php bin/w model:rebuild -m Weline_Demo -n Page',
                '重建指定模型（完整路径）' => 'php bin/w model:rebuild -m Weline_Demo -n Page/LocalDescription',
                '使用长选项' => 'php bin/w model:rebuild --module=Weline_Demo --name=Page',
            ],
            'php bin/w model:rebuild -m <模块名> [-n <模型名>]'
        );
    }
}
