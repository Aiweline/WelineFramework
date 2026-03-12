<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/26 15:02:25
 */

namespace Weline\Framework\Setup\Console\Setup;


use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\Register;
use Weline\Framework\Rules\RulesManager;
use Weline\Framework\Setup\Stage\StageUpdateManager;
use Weline\Framework\Setup\Stage\RouteUpdateStage;
use Weline\Framework\Setup\Stage\FileUpdateStage;
use Weline\Framework\Setup\Stage\DatabaseUpdateStage;
use Weline\Framework\Setup\Stage\FrameworkDbBootstrapStage;
use Weline\Framework\Setup\Stage\ModuleSetupStage;
use Weline\Framework\Setup\Stage\EavSchemaStage;
use Weline\Framework\Setup\Stage\SchemaDiffStage;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\System\Text;
use Weline\Framework\Router\Service\RouteUpdateService;
use Weline\Framework\Registry\Service\RegistryUpdateService;
use Weline\Framework\Console\ParseModuleArgsTrait;

class Upgrade implements \Weline\Framework\Console\CommandInterface
{
    use ParseModuleArgsTrait;

    /** 阶段 code 名（用于 --stage= 只运行指定阶段，便于单阶段测试） */
    public const STAGE_MODULE_SETUP = 'module_setup';
    public const STAGE_FRAMEWORK_DB_BOOTSTRAP = 'framework_db_bootstrap';
    public const STAGE_MODULE_MANAGER_BOOTSTRAP = 'module_manager_bootstrap';
    public const STAGE_EAV_SCHEMA = 'eav_schema';
    public const STAGE_SCHEMA_DIFF = 'schema_diff';
    public const STAGE_DATABASE_UPDATE = 'database_update';
    public const STAGE_ROUTE_UPDATE = 'route_update';
    public const STAGE_FILE_UPDATE = 'file_update';
    private const EVENT_CLEANUP_MISSING_MODULE_ACL_RESIDUES = 'Weline_Framework_Setup::cleanup_missing_module_acl_residues';
    private const EVENT_COLLECT_TAGLIB_REGISTRY = 'Weline_Framework_Setup::collect_taglib_registry';

    /** 所有阶段 code 列表（按执行顺序） */
    public const STAGE_CODES_ORDERED = [
        self::STAGE_MODULE_SETUP,
        self::STAGE_FRAMEWORK_DB_BOOTSTRAP,
        self::STAGE_MODULE_MANAGER_BOOTSTRAP,
        self::STAGE_EAV_SCHEMA,
        self::STAGE_SCHEMA_DIFF,
        self::STAGE_DATABASE_UPDATE,
        self::STAGE_ROUTE_UPDATE,
        self::STAGE_FILE_UPDATE,
    ];

    /** setup:upgrade 支持的参数键（用于严格校验未知参数） */
    private const SUPPORTED_ARG_KEYS = [
        'model',
        'route',
        'module',
        'm',
        'stage',
        'hot',
        'h',
        'skip-env-check',
        's',
        'force',
        'f',
        'skip-reflection-compile',
        'skip-reflect',
        'skip-background-optimize',
        'sync',
        'y',
        'yes',
        '-y',
        '--yes',
        'help',
        '-h',
        '--help',
    ];

    /** 用于错误提示的参数展示顺序 */
    private const SUPPORTED_ARGS_DISPLAY = [
        '--model',
        '--route',
        '--module, -m',
        '--stage',
        '--hot',
        '--skip-env-check, -s',
        '--force, -f',
        '--skip-reflection-compile, --skip-reflect',
        '--skip-background-optimize, --sync',
        '--yes, -y',
        '--help, -h',
    ];

    /**
     * 记录是否有模块被安装或升级
     * @var bool
     */
    private bool $hasModuleInstalledOrUpgraded = false;
    
    /**
     * 标识符文件路径，用于标记是否需要再次收集信息
     * @var string
     */
    private string $recollectFlagFile = '';
    
    /**
     * 记录本次升级是否已经收集过注册表（避免重复收集）
     * 遵循SOLID原则：通过状态标志控制流程，避免重复操作
     * @var bool
     */
    private bool $registryCollectedInThisRun = false;

    function __construct(
        private Printing $printing
    )
    {
        // 构造函数只负责初始化，不执行具体逻辑
        // 所有逻辑都在 execute() 方法中按正确顺序执行
    }

    /**
     * 在 setup:upgrade 因异常卸载/搬迁中断前，先清理这些模块遗留的 ACL / 菜单。
     *
     * @param string[] $moduleNames
     */
    private function cleanupMissingModuleAclResidues(array $moduleNames): void
    {
        $moduleNames = array_values(array_filter(array_unique($moduleNames)));
        if (empty($moduleNames)) {
            return;
        }

        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'module_names' => $moduleNames,
                'cleaned_count' => 0,
                'result' => null,
            ];
            $eventsManager->dispatch(self::EVENT_CLEANUP_MISSING_MODULE_ACL_RESIDUES, $eventData);
            $cleanedCount = (int)($eventData['cleaned_count'] ?? 0);
            if ($cleanedCount > 0) {
                $this->printing->note(__('已清理异常卸载模块 ACL/菜单残留 %{1} 条：%{2}', [
                    $cleanedCount,
                    implode(', ', $moduleNames),
                ]));
            }
        } catch (\Throwable $e) {
            $this->printing->warning(__('清理异常卸载模块 ACL/菜单残留失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 运行 composer dump-autoload 命令
     * 如果找不到 composer 命令则抛出异常
     * 
     * @return void
     * @throws Exception
     */
    private function runComposerDump(): void
    {
        $this->printing->note(__('正在检查 composer 命令...'));
        
        // 检查 composer 命令是否存在
        $composerCommand = $this->findComposerCommand();
        
        if (!$composerCommand) {
            throw new Exception(__('未找到 composer 命令！请确保 composer 已安装并添加到系统 PATH 中。'));
        }
        
        $this->printing->note(__('找到 composer 命令: %{1}', [$composerCommand]));
        
        // 在运行 composer dump-autoload 之前，先清理 generated/code 目录
        // 这样可以避免 Composer 扫描不存在的拦截器文件
        $this->printing->note(__('正在清理 generated/code 目录...'));
        $this->cleanGeneratedCodeDirectory();
        
        $this->printing->note(__('正在运行 composer dump-autoload...'));
        
        // 执行 composer dump-autoload
        // 先切换到项目根目录
        $originalCwd = getcwd();
        try {
            chdir(BP);
        } catch (\Exception $e) {
            throw new Exception(__('无法切换到项目根目录: %{1}', [$e->getMessage()]));
        }
        
        try {
            /** @var System $system */
            $system = ObjectManager::getInstance(System::class);
            
            // 构建命令（不需要 cd，因为已经切换了目录）
            $command = $composerCommand . ' dump-autoload';
            
            $result = $system->exec($command);
            // 检查返回码
            $returnCode = $result['return_vars'] ?? -1;
            if ($returnCode !== 0) {
                $errorOutput = implode("\n", $result['output'] ?? []);
                throw new Exception(__('composer dump-autoload 执行失败（返回码: %{1}）: %{2}', [$returnCode, $errorOutput]));
            }
            
            $this->printing->success(__('✓ composer dump-autoload 执行成功。'));
            
            // 输出 composer 的输出信息（如果有）
            if (!empty($result['output'])) {
                foreach ($result['output'] as $line) {
                    if (trim($line) !== '') {
                        $this->printing->printing($line);
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果是我们抛出的异常，直接抛出
            if (strpos($e->getMessage(), 'composer dump-autoload') !== false || 
                strpos($e->getMessage(), '未找到 composer') !== false ||
                strpos($e->getMessage(), '无法切换到项目根目录') !== false) {
                throw $e;
            }
            // 其他异常也抛出
            throw new Exception(__('执行 composer dump-autoload 时发生错误: %{1}', [$e->getMessage()]));
        } finally {
            // 恢复原始工作目录
            if (isset($originalCwd) && $originalCwd) {
                @chdir($originalCwd);
            }
        }
    }
    
    /**
     * 查找 composer 命令路径
     * 优先检查 composer.phar，然后检查全局 composer 命令
     * 
     * @return string|null
     */
    private function findComposerCommand(): ?string
    {
        // 1. 检查项目根目录下的 composer.phar
        // 注意：在 Windows 上，is_executable() 对 .phar 文件可能返回 false
        // 所以只要文件存在，就尝试使用 PHP 执行它
        $composerPhar = BP . 'composer.phar';
        if (file_exists($composerPhar)) {
            // 验证文件是否真的是 composer.phar（尝试执行 --version）
            $testCommand = PHP_BINARY . ' ' . escapeshellarg($composerPhar) . ' --version 2>&1';
            exec($testCommand, $testOutput, $testReturnCode);
            if ($testReturnCode === 0) {
                return PHP_BINARY . ' ' . $composerPhar;
            }
        }
        
        // 2. 检查全局 composer 命令（Windows 使用 where，Linux/Mac 使用 which）
        $checkCommand = IS_WIN ? 'where composer' : 'which composer';
        exec($checkCommand . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output[0])) {
            $composerPath = trim($output[0]);
            // 验证找到的路径是否有效
            if (file_exists($composerPath)) {
                return $composerPath;
            }
            // 如果路径包含 composer，也尝试使用（可能是符号链接）
            if (strpos($composerPath, 'composer') !== false) {
                // 验证命令是否可用
                exec($composerPath . ' --version 2>&1', $verifyOutput, $verifyReturnCode);
                if ($verifyReturnCode === 0) {
                    return $composerPath;
                }
            }
        }
        
        // 3. 尝试直接使用 composer（可能在 PATH 中）
        exec('composer --version 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            return 'composer';
        }
        
        return null;
    }
    
    /**
     * 清理 generated/code 目录
     * 在运行 composer dump-autoload 之前清理，避免扫描不存在的拦截器文件
     * 
     * @return void
     */
    private function cleanGeneratedCodeDirectory(): void
    {
        $generatedCodePath = BP . 'generated' . DS . 'code';
        
        if (!is_dir($generatedCodePath)) {
            // 目录不存在，创建它
            mkdir($generatedCodePath, 0755, true);
            return;
        }
        
        // 清理目录内容，但保留目录本身
        /** @var System $system */
        $system = ObjectManager::getInstance(System::class);
        
        try {
            // 扫描目录中的文件和子目录
            $files = scandir($generatedCodePath);
            $hasContent = false;
            
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $filePath = $generatedCodePath . DS . $file;
                    if (is_dir($filePath)) {
                        $system->exec('rm -rf ' . escapeshellarg($filePath));
                        $hasContent = true;
                    } elseif (is_file($filePath)) {
                        @unlink($filePath);
                        $hasContent = true;
                    }
                }
            }
            
            if ($hasContent) {
                $this->printing->success(__('✓ generated/code 目录已清理。'));
            } else {
                $this->printing->note(__('generated/code 目录为空，无需清理。'));
            }
        } catch (\Exception $e) {
            // 清理失败不影响主流程，只记录警告
            $this->printing->warning(__('清理 generated/code 目录时发生错误: %{1}，将继续执行。', [$e->getMessage()]));
        }
    }

    /**
     * @inheritDoc
     * 系统升级主流程，按照 SOLID 原则组织：
     * 1. 准备阶段：获取锁、检查系统状态、准备环境
     * 2. 执行阶段：收集注册表、执行升级、重新收集（如需要）
     * 3. 清理阶段：释放锁、关闭维护模式
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->validateSupportedArgs($args);

        // 检查是否为热更新模式
        if (isset($args['hot']) || isset($args['h'])) {
            $this->executeHotReload();
            return;
        }
        
        $lockFile = $this->getLockFile();
        $lockHandle = null;
        $maintenanceEnabled = false;
        
        try {
            // ========== 准备阶段 ==========
            $this->prepareUpgrade($lockFile, $lockHandle, $args);
            // 检查系统是否已安装
            if (!$this->checkSystemInstalled()) {
                $this->releaseLock($lockHandle, $lockFile);
                $this->handleSystemNotInstalled($args);
                return;
            }
            
            // ========== 执行阶段 ==========
            $this->executeUpgradeProcess($args, $data, $maintenanceEnabled);
            
            // ========== 完成阶段 ==========
            $this->completeUpgrade($args);
            
            // 通知 WLS 服务器热重载（如果正在运行）
            $this->notifyWlsReload();
            
        } catch (\Exception $e) {
            $this->printing->error(__('系统升级过程中发生错误：%{1}', [$e->getMessage()]));
            throw $e;
        } finally {
            // ========== 清理阶段 ==========
            $this->cleanupUpgrade($lockHandle, $lockFile, $maintenanceEnabled);
        }
    }

    /**
     * 严格校验 setup:upgrade 参数，避免误传参数被静默忽略
     *
     * @param array $args
     * @return void
     * @throws Exception
     */
    private function validateSupportedArgs(array $args): void
    {
        $unknownArgs = [];
        foreach ($args as $key => $value) {
            // 数字键为位置参数（模块名），不参与选项校验
            if (is_int($key) || ctype_digit((string) $key)) {
                continue;
            }
            // 控制台框架注入的内部参数，不作为命令选项
            if ($key === 'command') {
                continue;
            }
            if (!in_array($key, self::SUPPORTED_ARG_KEYS, true)) {
                $unknownArgs[] = $this->formatArgForDisplay((string) $key);
            }
        }
        if ($unknownArgs === []) {
            return;
        }
        $unknownArgs = array_values(array_unique($unknownArgs));
        sort($unknownArgs);
        throw new Exception(__('指定了不存在的参数：%{1}。可用参数：%{2}', [
            implode(', ', $unknownArgs),
            implode(', ', self::SUPPORTED_ARGS_DISPLAY),
        ]));
    }

    private function formatArgForDisplay(string $argKey): string
    {
        if (str_starts_with($argKey, '--') || str_starts_with($argKey, '-')) {
            return $argKey;
        }
        if (strlen($argKey) === 1) {
            return '-' . $argKey;
        }
        return '--' . $argKey;
    }
    
    /**
     * 准备升级：获取锁、清理环境、运行 composer
     * 
     * @param string $lockFile 锁文件路径
     * @param mixed $lockHandle 锁句柄（引用传递）
     * @param array $args 命令参数
     * @return void
     * @throws Exception
     */
    private function prepareUpgrade(string $lockFile, &$lockHandle, array $args = []): void
    {
        // 1. 获取文件锁
        try {
            $lockHandle = $this->acquireLock($lockFile);
            if ($lockHandle === null) {
                $this->printing->warning(__('系统升级命令正在执行中，请稍后再试。'));
                $this->printing->note(__('如果确认没有其他升级进程在运行，可以手动删除锁文件：%{1}', [$lockFile]));
                exit(1);
            }
        } catch (\Exception $e) {
            $this->printing->error(__('获取升级锁失败：%{1}', [$e->getMessage()]));
            exit(1);
        }
        
        // 2. 启用延迟注册表更新模式（优化：避免每个模块注册时都触发完整的注册表更新）
        // 遵循SOLID原则：单一职责 - 注册表更新由顶层统一管理
        Handle::setDeferRegistryUpdate(true);
        $this->printing->note(__('已启用延迟注册表更新模式（批量优化）'));
        
        // 3. 创建标识符文件，标记需要再次收集（升级过程中可能有新模块安装）
        $this->createRecollectFlag();
        
        // 4. 运行 composer dump-autoload（必须在注册表收集之前）
        $this->runComposerDump();
        
        // 5. 环境依赖检测（必须在 extends/注册表更新前执行）
        $this->checkEnvironmentDependencies($args);
        
        // 6. 收集框架注册表（必须在升级前完成，确保系统可用）
        // 如果指定了模块，则使用增量更新模式
        $this->printing->note(__('正在准备系统环境...'));
        $argsModule = $this->parseModuleArgs($args);
        $this->collectFrameworkRegistries(true, $argsModule);
        
        // 7. 验证框架约束规则（必须在模块升级前验证，遵循框架约束）
        $this->validateFrameworkRules();
    }
    
    /**
     * 环境依赖检测
     * 
     * 在 extends/注册表更新运行前执行环境依赖检测。
     * 如果检测不通过，提示用户并提供自动修复选项。
     * 
     * @param array $args 命令参数
     * @return void
     */
    private function checkEnvironmentDependencies(array $args): void
    {
        // 检查是否跳过环境检测（支持 --force, --skip-env-check, -s）
        if (isset($args['skip-env-check']) || isset($args['s']) || isset($args['force']) || isset($args['f'])) {
            $this->printing->note(__('已跳过环境依赖检测'));
            return;
        }
        
        $this->printing->note(__('正在检测环境依赖...'));
        
        try {
            // 获取收集器和检查器
            /** @var \Weline\Framework\Env\Service\EnvRequirementsCollector $collector */
            $collector = ObjectManager::getInstance(\Weline\Framework\Env\Service\EnvRequirementsCollector::class);
            
            /** @var \Weline\Framework\Env\Service\EnvChecker $checker */
            $checker = ObjectManager::getInstance(\Weline\Framework\Env\Service\EnvChecker::class);
            
            // 收集环境需求
            $requirements = $collector->collect();
            
            // 执行检测
            $checker->setRequirements($requirements);
            $result = $checker->check();
            
            if (!$result->hasError()) {
                $this->printing->success(__('✓ 环境依赖检测通过'));
                return;
            }
            
            // 环境检测不通过，直接尝试自动修复
            $this->printing->warning(__('环境依赖检测未通过，正在自动修复...'));
            $this->printing->note('');
            
            /** @var \Weline\Framework\Env\Console\Env\Install $envInstall */
            $envInstall = ObjectManager::getInstance(\Weline\Framework\Env\Console\Env\Install::class);
            $envInstall->execute(['y' => true], []);
            
            // 修复后重新检测
            $this->printing->note(__('自动修复完成，重新检测环境...'));
            $requirements = $collector->collect();
            $checker->setRequirements($requirements);
            $result = $checker->check();
            
            if (!$result->hasError()) {
                $this->printing->success(__('✓ 环境问题已全部修复'));
            } else {
                // 仍有问题，显示详情并询问用户
                $this->printing->warning(__('部分问题未能自动修复：'));
                $this->printing->note('');
                
                if ($result->getPhpVersionIssue()) {
                    $this->printing->error(__('PHP 版本问题: %{issue}', ['issue' => $result->getPhpVersionIssue()]));
                }
                
                $missing = $result->getMissingExtensions();
                if (!empty($missing)) {
                    $this->printing->error(__('缺失的扩展: %{exts}', ['exts' => implode(', ', $missing)]));
                }
                
                $disabled = $result->getDisabledFunctions();
                if (!empty($disabled)) {
                    $this->printing->error(__('被禁用的函数: %{funcs}', ['funcs' => implode(', ', $disabled)]));
                }
                
                $unsatisfied = $result->getUnsatisfiedItems();
                if (!empty($unsatisfied)) {
                    foreach ($unsatisfied as $item) {
                        $name = $item['name'] ?? __('未命名');
                        $this->printing->error(__('未满足的依赖: %{name}', ['name' => $name]));
                    }
                }
                
                $this->printing->note('');
                $this->printing->warning(__('您可以选择：'));
                $this->printing->note(__('1. 仍继续升级（可能导致问题）'));
                $this->printing->note(__('2. 退出升级，手动修复后重试'));
                $this->printing->setup(__('请选择 (1/2)：'));
                
                /** @var \Weline\Framework\App\System $system */
                $system = ObjectManager::getInstance(\Weline\Framework\App\System::class);
                $input = trim($system->input() ?? '');
                
                if ($input === '1') {
                    $this->printing->warning(__('您选择继续升级，可能会遇到问题'));
                } else {
                    $this->printing->note(__('升级已取消'));
                    $this->printing->note(__('请运行 php bin/w env:check 查看环境问题'));
                    $this->printing->note(__('运行 php bin/w env:install 尝试自动修复'));
                    throw new Exception(__('用户选择退出升级'));
                }
            }
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '用户选择') !== false) {
                throw $e;
            }
            // 环境检测本身出错，记录警告但不阻断升级
            w_log_warning(__('环境依赖检测出错: %{1}', [$e->getMessage()]), [], 'env_check.log');
            $this->printing->warning(__('环境依赖检测出错：%{1}，继续执行升级...', [$e->getMessage()]));
        }
    }
    
    /**
     * 验证框架约束规则
     * 
     * 职责：调用规则管理器验证所有框架约束规则
     * 
     * @return void
     * @throws Exception 如果有任何规则验证失败
     */
    private function validateFrameworkRules(): void
    {
        try {
            /** @var RulesManager $rulesManager */
            $rulesManager = ObjectManager::getInstance(RulesManager::class);
            $rulesManager->validateAll();
        } catch (Exception $e) {
            // 规则验证失败，必须停止升级
            // 这是框架约束，不允许继续执行
            throw $e;
        } catch (\Throwable $e) {
            // 规则管理器本身的错误（如找不到规则类等），也停止升级
            // 确保规则系统正常工作
            $this->printing->error(__('框架约束规则系统错误：%{1}', [$e->getMessage()]));
            throw new Exception(__('框架约束规则系统错误，无法继续升级。请检查规则系统配置。'));
        }
    }
    
    /**
     * 执行升级流程：维护模式、模块升级、注册表重新收集
     * 
     * @param array $args 命令参数
     * @param array $data 命令数据
     * @param bool $maintenanceEnabled 维护模式状态（引用传递）
     * @return void
     * @throws Exception
     */
    private function executeUpgradeProcess(array $args, array $data, bool &$maintenanceEnabled): void
    {
        
        // 1. 启用维护模式
        Env::getInstance()->setConfig('system.maintenance', true);
        $maintenanceEnabled = true;
        $this->printing->note(__('系统已设置为维护模式，开始执行升级...'));
        
        // 2. 执行模块升级流程
        $this->executeModuleUpgrade($args, $data);
        
        // 3. 检测是否有模块被安装或升级，如果有则重新收集注册表
        if ($this->hasModuleInstalledOrUpgraded) {
            $this->printing->note(__('检测到模块安装或升级，正在重新收集注册表信息...'));
            $this->recollectRegistryAfterModuleChange();
        }
        
        // 4. 触发系统升级后事件（传递部分更新模式参数）
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        // 检查是否指定了部分更新模式
        $doModel = isset($args['model']);
        $doRoute = isset($args['route']);
        $isPartialUpgrade = $doModel || $doRoute;
        $eventData = [
            'is_partial_upgrade' => $isPartialUpgrade,
            'route_only' => $doRoute,
            'model_only' => $doModel,
            'args' => $args
        ];
        $eventsManager->dispatch('Weline_Framework_Setup::upgrade_after', $eventData);
        
        // 5. 检查是否需要再次收集（升级过程中可能有新模块安装）
        $this->checkAndRecollectIfNeeded();
    }
    
    /**
     * 完成升级：显示成功信息
     *
     * @param array $args 命令参数（用于 --skip-reflection-compile 等）
     * @return void
     */
    private function completeUpgrade(array $args = []): void
    {
        // 检查是否跳过后台优化或强制同步执行
        $skipBackgroundOptimize = isset($args['skip-background-optimize']) || isset($args['sync']);
        
        if ($skipBackgroundOptimize) {
            // 同步执行优化缓存生成
            $this->generateOptimizationCache($args);
        } else {
            // 后台异步执行优化缓存生成（不占用升级时间）
            $this->startBackgroundOptimize($args);
        }
        
        $this->printing->success(__('系统升级完成！'));
    }
    
    /**
     * 在后台启动优化缓存生成任务
     * 
     * 使用 Processer::create() 创建后台进程执行 setup:background-optimize 命令。
     * 这样可以避免类映射、PSR-4 映射和反射编译占用升级主流程时间。
     *
     * @param array $args 命令参数
     * @return void
     */
    private function startBackgroundOptimize(array $args = []): void
    {
        $this->printing->note(__('正在启动后台优化任务...'));
        
        $phpBin = PHP_BINARY ?: 'php';
        $binW = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        
        // 构建命令（命令名根据类名自动转换为 setup:backgroundoptimize）
        $cmd = '"' . $phpBin . '" "' . $binW . '" setup:backgroundoptimize';
        
        // 传递跳过反射编译参数
        if (isset($args['skip-reflection-compile']) || isset($args['skip-reflect'])) {
            $cmd .= ' --skip-reflection-compile';
        }
        
        // 添加进程名标识
        $processName = 'weline-setup-background-optimize-' . time();
        $cmd .= ' --name=' . $processName;
        
        try {
            // 使用进程管理器在后台启动任务（非阻塞）
            $pid = \Weline\Framework\System\Process\Processer::create($cmd, false);
            
            if ($pid > 0) {
                $this->printing->success(__('✓ 后台优化任务已启动 (PID: %{1})', [$pid]));
                $this->printing->note(__('  优化内容：类映射缓存、PSR-4 映射、反射编译'));
                $this->printing->note(__('  日志文件：var/log/setup_background_optimize.log'));
            } else {
                // 启动失败，回退到同步执行
                $this->printing->warning(__('后台任务启动失败，改为同步执行...'));
                $this->generateOptimizationCache($args);
            }
        } catch (\Throwable $e) {
            // 启动失败，回退到同步执行
            $this->printing->warning(__('后台任务启动异常: %{1}，改为同步执行...', [$e->getMessage()]));
            $this->generateOptimizationCache($args);
        }
    }
    
    /**
     * 生成优化缓存：类映射和 PSR-4 映射
     * 这些缓存在运行时只读取，不更新，以提高性能
     *
     * @param array $args 命令参数（支持 --skip-reflection-compile / --skip-reflect）
     * @return void
     */
    private function generateOptimizationCache(array $args = []): void
    {
        $this->printing->note(__('正在生成优化缓存...'));
        
        // 1. 生成类映射缓存
        $this->generateClassmapCache();
        
        // 2. 生成 PSR-4 映射缓存
        $this->generatePsr4Cache();
        
        // 3. 编译反射元数据与编译型工厂（reflection_metadata.php + compiled_factories.php）
        $skipReflectionCompile = isset($args['skip-reflection-compile']) || isset($args['skip-reflect']);
        if ($skipReflectionCompile) {
            $this->printing->note(__('已跳过反射/工厂编译，需要时可执行：php bin/w reflection:compile'));
        } else {
            $this->compileReflectionAndFactories();
        }
        
        $this->printing->success(__('✓ 优化缓存生成完成。'));
    }
    
    /**
     * 执行 reflection:compile，生成反射元数据和编译型工厂容器
     * 在子进程中运行，避免代码质量问题导致的 fatal error 阻断升级流程
     */
    private function compileReflectionAndFactories(): void
    {
        try {
            $phpBin = PHP_BINARY ?: 'php';
            $binW = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
            $cmd = '"' . $phpBin . '" "' . $binW . '" reflection:compile 2>&1';
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);
            // 输出编译结果
            if ($outputStr) {
                echo $outputStr . "\n";
            }
            if ($exitCode !== 0) {
                $this->printing->warning(__('反射/工厂编译出现警告（exit=%{1}），不影响系统功能。', [$exitCode]));
            }
        } catch (\Throwable $e) {
            $this->printing->warning(__('反射/工厂编译跳过：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 生成类映射缓存
     * 扫描 app/code 和 generated/code 目录，生成类名到文件路径的映射
     * 
     * @return void
     */
    private function generateClassmapCache(): void
    {
        $classMap = [];
        $directories = [
            APP_CODE_PATH,
            BP . 'generated' . DS . 'code' . DS,
        ];
        
        foreach ($directories as $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }
            
            $this->scanDirectoryForClasses($baseDir, $baseDir, $classMap);
        }
        
        // 保存类映射缓存
        $classMapFile = BP . 'generated' . DS . 'classmap.php';
        $content = '<?php' . PHP_EOL;
        $content .= '// 类映射缓存 - 由 setup:upgrade 自动生成' . PHP_EOL;
        $content .= '// 生成时间: ' . date('Y-m-d H:i:s') . PHP_EOL;
        $content .= 'return ' . var_export($classMap, true) . ';' . PHP_EOL;
        
        file_put_contents($classMapFile, $content, LOCK_EX);
        
        $this->printing->note(__('  - 类映射缓存已生成，共 %{1} 个类', [count($classMap)]));
    }
    
    /**
     * 递归扫描目录查找 PHP 类文件
     * 
     * @param string $dir 当前扫描目录
     * @param string $baseDir 基础目录（用于计算命名空间）
     * @param array $classMap 类映射数组（引用）
     * @return void
     */
    private function scanDirectoryForClasses(string $dir, string $baseDir, array &$classMap): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . $file;
            
            if (is_dir($path)) {
                $this->scanDirectoryForClasses($path . DS, $baseDir, $classMap);
            } elseif (str_ends_with($file, '.php')) {
                // 从文件内容解析实际的 namespace 和 class 名称
                // 这样可以正确处理大小写（如 extends vs Extends）
                $className = $this->extractFullyQualifiedClassName($path);
                
                if ($className !== null) {
                    $classMap[$className] = $path;
                }
            }
        }
    }
    
    /**
     * 从 PHP 文件中提取完整类名（namespace + class）
     * 
     * 通过解析文件内容获取实际声明的 namespace 和 class 名称，
     * 而不是从文件路径推断，确保大小写正确（解决 Linux 区分大小写问题）
     * 
     * @param string $filePath PHP 文件路径
     * @return string|null 完整类名，解析失败返回 null
     */
    private function extractFullyQualifiedClassName(string $filePath): ?string
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        // 只读取文件前 4KB（namespace 和 class 声明通常在文件开头）
        $content = substr($content, 0, 4096);
        
        $namespace = '';
        $className = '';
        
        // 解析 namespace
        if (preg_match('/^\s*namespace\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*)\s*;/m', $content, $matches)) {
            $namespace = $matches[1];
        }
        
        // 解析 class/interface/trait/enum 名称
        if (preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/m', $content, $matches)) {
            $className = $matches[1];
        }
        
        if ($className === '') {
            return null;
        }
        
        // 组合完整类名
        if ($namespace !== '') {
            return $namespace . '\\' . $className;
        }
        
        return $className;
    }
    
    /**
     * 生成 PSR-4 映射缓存
     * 
     * @return void
     */
    private function generatePsr4Cache(): void
    {
        // 加载 Composer 的 autoload
        $autoloader = VENDOR_PATH . 'autoload.php';
        if (!is_file($autoloader)) {
            $this->printing->warning(__('  - 跳过 PSR-4 缓存生成：Composer autoload 未找到'));
            return;
        }
        
        $composerLoader = require $autoloader;
        $psr4Map = $composerLoader->getPrefixesPsr4();
        $modifiedPsr4 = [];
        
        foreach ($psr4Map as $prefix => $paths) {
            $relativePath = str_replace('\\', DS, trim($prefix, '\\'));
            $appCodePath = APP_CODE_PATH . $relativePath . DS;
            
            if (is_dir($appCodePath)) {
                // 移除已存在的 app/code 路径
                $paths = array_filter($paths, function($path) use ($appCodePath) {
                    $normalizedPath = rtrim($path, DS) . DS;
                    return $normalizedPath !== $appCodePath;
                });
                // 将 app/code 路径添加到最前面
                array_unshift($paths, $appCodePath);
                $modifiedPsr4[$prefix] = array_values($paths);
            }
        }
        
        // 保存 PSR-4 映射缓存
        if (!empty($modifiedPsr4)) {
            $psr4CacheFile = BP . 'generated' . DS . 'psr4_map.php';
            $content = '<?php' . PHP_EOL;
            $content .= '// PSR-4 映射缓存 - 由 setup:upgrade 自动生成' . PHP_EOL;
            $content .= '// 生成时间: ' . date('Y-m-d H:i:s') . PHP_EOL;
            $content .= 'return ' . var_export($modifiedPsr4, true) . ';' . PHP_EOL;
            
            file_put_contents($psr4CacheFile, $content, LOCK_EX);
            
            $this->printing->note(__('  - PSR-4 映射缓存已生成，共 %{1} 个命名空间', [count($modifiedPsr4)]));
        } else {
            $this->printing->note(__('  - 跳过 PSR-4 缓存生成：没有需要优化的映射'));
        }
    }
    
    /**
     * 清理升级：释放锁、关闭维护模式
     * 
     * @param mixed $lockHandle 锁句柄
     * @param string $lockFile 锁文件路径
     * @param bool $maintenanceEnabled 维护模式是否已启用
     * @return void
     */
    private function cleanupUpgrade($lockHandle, string $lockFile, bool $maintenanceEnabled): void
    {
        // 1. 禁用延迟注册表更新模式（无论升级是否成功，都要恢复正常模式）
        // 遵循SOLID原则：确保状态在 finally 块中被正确清理
        if (Handle::isDeferRegistryUpdate()) {
            Handle::setDeferRegistryUpdate(false);
            $this->printing->note(__('已禁用延迟注册表更新模式'));
        }
        
        // 2. 释放锁
        $this->releaseLock($lockHandle, $lockFile);
        
        // 注意：标识符文件的清理在 checkAndRecollectIfNeeded() 中完成
        // 如果标识符文件在 cleanupUpgrade 时还存在，说明可能发生了异常
        // 这种情况下，标识符文件会保留到下次运行，以便检测到需要再次收集
        
        // 3. 关闭维护模式
        if ($maintenanceEnabled) {
            try {
                $result = Env::getInstance()->setConfig('system.maintenance', false);
                if ($result) {
                    $this->printing->note(__('维护模式已关闭。'));
                } else {
                    $this->printing->warning(__('关闭维护模式失败，配置可能未保存。请手动运行 php bin/w maintenance:disable 关闭维护模式。'));
                }
            } catch (\Exception $e) {
                $this->printing->warning(__('关闭维护模式时发生错误：%{1}。请手动运行 php bin/w maintenance:disable 关闭维护模式。', [$e->getMessage()]));
            }
        }
    }
    
    
    /**
     * 收集所有框架自带的注册表信息
     * 顺序：Extends -> 插件 -> 事件 -> Hook -> Tag
     * 系统更新前必须运行，更新后如果有模块安装或升级也要再次运行
     * 
     * 优化：统一管理所有注册表收集，包括 Tag 注册表
     * 遵循SOLID原则：单一职责 - 本方法负责所有注册表的收集
     * 
     * @param bool $includeTag 是否包含 Tag 注册表收集（默认true）
     * @param array $moduleNames 指定模块名列表，如果为空则更新所有（增量更新支持）
     * @return void
     */
    private function collectFrameworkRegistries(bool $includeTag = true, array $moduleNames = []): void
    {
        // 仅保留已注册的模块名，避免将 stage code（如 schema_diff）等误当作模块传入注册表/标签库收集
        $validModuleNames = array_keys(Env::getInstance()->getActiveModules());
        $moduleNames = array_values(array_intersect($moduleNames, $validModuleNames));

        // 使用统一服务更新所有注册表
        try {
            /** @var RegistryUpdateService $registryService */
            $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
            
            if (!empty($moduleNames)) {
                // 增量更新模式：只更新指定模块的注册表
                $this->printing->note(__('正在增量更新模块 %{1} 的注册表...', [implode(', ', $moduleNames)]));
                $ok = $registryService->updateModuleRegistriesIncremental($moduleNames);
                if ($ok) {
                    $this->printing->success(__('✓ 模块注册表增量更新完成。'));
                } else {
                    $this->printing->warning(__('部分注册表增量更新失败，但将继续执行。'));
                }
            } else {
                // 全量更新模式：更新所有注册表
                $this->printing->note(__('正在更新所有注册表...'));
                // 传入 false 强制跳过自动编译（升级流程中会在后面统一编译一次）
                // 跳过命令更新（第三个参数 true），因为 setup:upgrade 会在后面第 961-964 行单独执行 command:upgrade
                $ok = $registryService->updateAllRegistries(false, false, true);
                if ($ok) {
                    $this->printing->success(__('✓ 所有注册表已更新完成。'));
                } else {
                    $this->printing->warning(__('部分注册表更新失败，但将继续执行。'));
                }
            }
        } catch (\Exception $e) {
            // 检查是否是致命错误（包含"【致命错误】"标记）
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, '【致命错误】')) {
                // 在开发环境下，致命错误必须中断系统更新
                if (defined('DEV') && DEV) {
                    $this->printing->error(__('注册表更新失败（致命错误）: %{1}', [$errorMessage]));
                    w_log_error(__('注册表更新失败（致命错误）: %{1}', [$errorMessage]), [], 'registry_update.log');
                    throw $e; // 重新抛出异常，中断系统更新
                }
            }
            // 注册表更新失败不影响系统更新，只记录错误日志
            w_log_warning(__('注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
            $this->printing->warning(__('注册表更新失败：%{1}，但将继续执行。', [$e->getMessage()]));
        }
        
        // 收集 Tag 注册表（Tag 不是框架自带的，需要单独收集）
        if ($includeTag) {
            try {
                $this->printing->note(__('收集标签注册表...'));
                /** @var EventsManager $eventsManager */
                $eventsManager = ObjectManager::getInstance(EventsManager::class);
                $taglibEventData = [
                    'module_names' => $moduleNames,
                    'skip_template_cache_clear' => true,
                    'result' => null,
                ];
                $eventsManager->dispatch(self::EVENT_COLLECT_TAGLIB_REGISTRY, $taglibEventData);
                $taglibResult = $taglibEventData['result'] ?? null;
                if (is_array($taglibResult) && isset($taglibResult['success']) && !$taglibResult['success']) {
                    $this->printing->warning(__('标签注册表收集失败：%{1}', [$taglibResult['message'] ?? '']));
                } else {
                    $this->printing->success(__('✓ 标签注册表已收集完成。'));
                }
            } catch (\Exception $e) {
                // 标签收集失败不影响系统更新，只记录警告
                w_log_warning(__('标签注册表收集失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
                $this->printing->warning(__('标签注册表收集时发生错误：%{1}，但将继续执行。', [$e->getMessage()]));
            }
        }
    }
    
    /**
     * 在模块安装或升级后重新收集注册表信息
     * 包括：框架注册表（Extends、插件、事件、Hook）和其他注册表（Tag）
     * 
     * 优化：使用 registryCollectedInThisRun 标志避免重复收集
     * 遵循SOLID原则：单一职责 - 本方法只负责触发收集，不重复执行
     * 
     * @param bool $force 是否强制收集（忽略已收集标志）
     * @return void
     */
    private function recollectRegistryAfterModuleChange(bool $force = false): void
    {
        // 优化：如果本次升级已经收集过注册表，且不是强制收集，则跳过
        if ($this->registryCollectedInThisRun && !$force) {
            $this->printing->note(__('注册表已在本次升级中收集过，跳过重复收集'));
            return;
        }
        
        // 重新收集所有注册表（包括框架注册表和 Tag 注册表）
        // 优化：collectFrameworkRegistries 已统一管理所有注册表的收集
        $this->printing->note(__('检测到模块安装或升级，正在重新收集所有注册表信息...'));
        $this->collectFrameworkRegistries(true);
        
        // 标记本次升级已经收集过注册表
        $this->registryCollectedInThisRun = true;
    }
    
    /**
     * 执行模块升级流程（原 module:upgrade 的功能）
     * @param array $args
     * @param array $data
     * @return void
     * @throws Exception
     * @throws \ReflectionException
     */
    private function executeModuleUpgrade(array $args = [], array $data = []): void
    {
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        // 传递模块过滤信息，便于观察者按需执行（例如菜单、Widget 等）
        $beforeEventData = ['modules' => $argsModule ?? []];
        $eventsManager->dispatch('Weline_Framework_Module::module_upgrade_before', $beforeEventData);
        $appoint = false;
        // 支持 --module 和 -m 两种写法，以及位置参数
        $argsModule = $args['module'] ?? $args['m'] ?? [];
        if (is_string($argsModule)) {
            $argsModule = explode(' ', $argsModule);
        }
        
        // 如果没有通过 --module 或 -m 指定模块，检查位置参数
        if (empty($argsModule)) {
            // 检查是否有位置参数（非选项参数）
            $positionalArgs = [];
            foreach ($args as $key => $value) {
                // 如果是数字键且不是选项参数，则认为是位置参数
                // 排除命令本身（通常是第一个位置参数）
                if (is_numeric($key) && !str_starts_with($value, '-') && $key > 0) {
                    $positionalArgs[] = $value;
                }
            }
            if (!empty($positionalArgs)) {
                $argsModule = $positionalArgs;
            }
        }
        
        // 如果指定了模块，显示提示信息
        if ($argsModule) {
            $this->printing->setup(__('指定模块升级模式：仅升级 %{1}', [implode(', ', $argsModule)]));
        }

        // 解析 --stage=code 或 --stage code（只运行指定阶段，便于单阶段测试）
        $stageFilter = null;
        if (isset($args['stage'])) {
            $stageFilter = is_array($args['stage']) ? $args['stage'] : array_map('trim', explode(',', (string) $args['stage']));
            $stageFilter = array_values(array_filter($stageFilter, fn($s) => $s !== ''));
            $validCodes = self::STAGE_CODES_ORDERED;
            $unknown = array_diff($stageFilter, $validCodes);
            if ($unknown !== []) {
                throw new Exception(__('未知阶段 code：%{1}。可选：%{2}', [implode(', ', $unknown), implode(', ', $validCodes)]));
            }
            $this->printing->setup(__('仅运行指定阶段：%{1}', [implode(', ', $stageFilter)]));
        }
        $shouldCommitStage = fn(string $code): bool => $stageFilter === null || in_array($code, $stageFilter, true);
        
        // 检查是否指定了部分更新模式
        $doModel = isset($args['model']);
        $doRoute = isset($args['route']);
        $appoint = $doModel || $doRoute;
        
        if ($doModel) {
            $stageNum = 1;
            /**@var ModelManager $modelManager */
            $modelManager = ObjectManager::getInstance(ModelManager::class);
            /**@var Handle $module_handle */
            $module_handle = ObjectManager::getInstance(Handle::class);
            // 安装Setup信息
            $this->printing->note($stageNum . '、指定安装Setup信息...', '系统');
            $modules = $module_handle->getModules();
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                if (is_file($module['base_path'] . '/register.php')) {
                    require $module['base_path'] . '/register.php';
                }
                $module_handle->setupInstall(new Module($module));
            }
            // 注册模型数据库信息
            $stageNum++;
            $this->printing->note($stageNum . '、指定注册模型数据库信息...', '系统');
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                $module_handle->setupInstall(new Module($module));
                $module_handle->setupModel(new Module($module));
            }
        }
        
        if ($doRoute) {
            // 🔧 路由注册会触发 ControllerAttributes 查询 m_acl 等表，必须先提交 SchemaDiff 确保表已存在
            $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $frameworkDbBootstrapStage = ObjectManager::make(FrameworkDbBootstrapStage::class, ['connectionFactory' => $connectionFactory]);
            $frameworkDbBootstrapStage->prepare([]);
            $frameworkDbBootstrapStage->commit();

            /** @var Handle $routeModuleHandle */
            $routeModuleHandle = ObjectManager::getInstance(Handle::class);
            $eavSchemaStage = ObjectManager::make(EavSchemaStage::class, [
                'eventsManager' => ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class),
                'migrationModel' => ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class),
                'printing' => $this->printing,
            ]);
            $eavSchemaStage->prepare([]);
            $eavSchemaStage->commit();

            $schemaDiffStage = ObjectManager::make(SchemaDiffStage::class, [
                'moduleHandle' => $routeModuleHandle,
                'moduleReader' => ObjectManager::getInstance(\Weline\Framework\Module\Config\ModuleFileReader::class),
                'connectionFactory' => $connectionFactory,
                'schemaParser' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaParser::class),
                'dbSchemaReader' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\DbSchemaReader::class),
                'diffEngine' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaDiffEngine::class),
                'executor' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaMigrationExecutor::class),
                'printing' => $this->printing,
            ]);
            $schemaDiffStage->prepare([]);
            $schemaDiffStage->commit();

            // 使用独立的路由更新服务
            // 注意：路由更新不依赖 register.php，register.php 只负责模块注册
            $stageNum = 1;
            $this->printing->note($stageNum . '、指定注册路由信息...', '系统');

            /**@var RouteUpdateService $routeUpdateService */
            $routeUpdateService = ObjectManager::make(RouteUpdateService::class, [
                'printing' => $this->printing,
                'moduleHandle' => $routeModuleHandle
            ]);

            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $beforeRouteCollectionEventData = [];
            $eventsManager->dispatch('Weline_Framework_Setup::before_route_collection', $beforeRouteCollectionEventData);

            // 更新路由（支持指定模块）
            $routeUpdateService->updateRoutes($argsModule ?: []);
            $afterRouteCollectionEventData = [];
            $eventsManager->dispatch('Weline_Framework_Setup::after_route_collection', $afterRouteCollectionEventData);
        }
        
        if ($appoint) {
            $this->printing->success(__('委托部分更新已运行！'));
            return;
        }
        
        $i = 1;
        // 如果没有指定模块，执行全局清理操作
        if (!$argsModule) {
            //        // 删除路由文件
            $this->printing->warning($i . '、路由更新...', '系统');
            $this->printing->warning('清除文件：');
            /**@var System $system */
            $system = ObjectManager::getInstance(System::class);
            foreach (Env::router_files_PATH as $path) {
                $this->printing->warning($path);
                if (is_file($path)) {
                    $data = $system->exec('rm -f ' . $path);
                    if ($data) {
                        $this->printing->printList($data);
                    }
                }
            }
            $i += 1;
            $this->printing->note($i . '、命令行更新...');
            /**@var \Weline\Framework\Console\Console\Command\Upgrade $commandManagerConsole */
            $commandManagerConsole = ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class);
            $commandManagerConsole->execute();

            $this->printing->note($i . '、事件清理...');
            /**@var \Weline\Framework\Event\Console\Event\Cache\Clear $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Event\Console\Event\Cache\Clear::class);
            $cacheManagerConsole->execute();

            $i += 1;
            $this->printing->note($i . '、插件编译...');
            /**@var \Weline\Framework\Plugin\Console\Plugin\Di\Compile $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
            $cacheManagerConsole->execute();
            $i += 1;
        } else {
            // 指定模块升级时，使用增量更新方式处理命令、事件、插件等
            $this->printing->note('指定模块升级，使用增量更新模式...');
            /** @var RegistryUpdateService $registryService */
            $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
            $registryService->updateModuleRegistriesIncremental($argsModule);
            $i += 1;
        }
        
        // 扫描代码
        $this->printing->note($i . '、清理模板缓存', '系统');
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        /**@var System $system */
        $system = ObjectManager::getInstance(System::class);
        foreach ($origin_vendor_modules as $modules) {
            foreach ($modules as $module) {
                if ($argsModule and !in_array($module['name'], $argsModule)) {
                    continue;
                }
                $tpl_dir = $module['base_path'] . DS . 'view' . DS . 'tpl';
                if (is_dir($tpl_dir)) {
                    $system->exec("rm -rf {$tpl_dir}");
                }
            }
        }
        
        if (!$argsModule) {
            $i += 1;
            $this->printing->note($i . '、清理缓存...');
            /**@var \Weline\Framework\Cache\Console\Cache\Flush $cacheManagerConsole */
            $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Flush::class);
            $cacheManagerConsole->execute();
            $system->exec('rm -rf ' . BP . 'var' . DS . 'cache');
        } elseif ($argsModule) {
            // 指定模块时，清理指定模块的缓存
            $i += 1;
            $this->printing->note($i . '、清理指定模块缓存...');
            foreach ($argsModule as $moduleName) {
                $this->printing->note(__('清理模块 %{1} 的缓存...', [$moduleName]));
                // 清理模块特定的缓存目录
                $moduleCacheDirs = [
                    BP . 'var' . DS . 'cache' . DS . strtolower(str_replace('_', DS, $moduleName)),
                    BP . 'generated' . DS . 'code' . DS . str_replace('_', '\\', $moduleName),
                    BP . 'generated' . DS . 'metadata' . DS . str_replace('_', '\\', $moduleName),
                ];
                foreach ($moduleCacheDirs as $cacheDir) {
                    if (is_dir($cacheDir)) {
                        $system->exec('rm -rf ' . $cacheDir);
                        $this->printing->success(__('已清理：%{1}', [$cacheDir]));
                    }
                }
            }
            // 清理模板缓存（已在前面处理，这里只是确保）
            $this->printing->note(__('指定模块缓存清理完成'));
        }

        $this->printing->note($i . '、module模块更新...');
        // 注册模块
        $all_modules = [];
        // 扫描模型注册代码
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        // 两阶段注册：先只执行 MODULE，再刷新依赖，再执行 THEME/ROUTER/i18n
        Register::setRegisterPhase(Register::PHASE_MODULE_ONLY);
        $this->printing->note(__('1)注册模组'));
        foreach ($dependencyModules as $module_name => $module) {
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
            if (is_file($module['register'])) {
                require $module['register'];
            }
        }
        $this->printing->note(__('2)刷新注册表（事件/Hook/Extends）'));
        // 强制重读模块列表并清理 Env 中依赖模块的缓存（active_module_list、module_configs），使事件/Hook/Extends 刷新时能扫到刚在 1) 中完成 MODULE 注册的模块（Theme、I18n 等），否则 register_installer 观察者不在表内，后续 runPendingRegistrations 会仍用不存在的 Framework\Theme\Handle、Framework\I18n\Handle；先更新 Env 再刷新注册表，确保新模块的 event/hook/extends 立即生效
        Env::getInstance()->getModuleList(true);
        /** @var RegistryUpdateService $registryService */
        $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
        // 跳过命令更新，因为后面会单独执行 command:upgrade
        if (!empty($argsModule)) {
            // 增量更新模式：只更新指定模块的注册表
            $registryService->updateModuleRegistriesIncremental($argsModule);
        } else {
            // 全量更新模式：更新所有注册表
            $registryService->updateAllRegistries(true, false, true);
        }
        // 🔧 runPendingRegistrations 会触发 Theme Installer 等查询 m_weline_theme 等表，必须先提交 SchemaDiff 确保表结构完整（如 module_name 等缺失列已添加）
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $preSchemaFrameworkBootstrap = ObjectManager::make(FrameworkDbBootstrapStage::class, ['connectionFactory' => $connectionFactory]);
        $preSchemaFrameworkBootstrap->prepare([]);
        $preSchemaFrameworkBootstrap->commit();
        /** @var Handle $preSchemaModuleHandle */
        $preSchemaModuleHandle = ObjectManager::getInstance(Handle::class);
        $preSchemaEavStage = ObjectManager::make(EavSchemaStage::class, [
            'eventsManager' => ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class),
            'migrationModel' => ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class),
            'printing' => $this->printing,
        ]);
        $preSchemaEavStage->prepare([]);
        $preSchemaEavStage->commit();
        $preSchemaDiffStage = ObjectManager::make(SchemaDiffStage::class, [
            'moduleHandle' => $preSchemaModuleHandle,
            'moduleReader' => ObjectManager::getInstance(\Weline\Framework\Module\Config\ModuleFileReader::class),
            'connectionFactory' => $connectionFactory,
            'schemaParser' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaParser::class),
            'dbSchemaReader' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\DbSchemaReader::class),
            'diffEngine' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaDiffEngine::class),
            'executor' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaMigrationExecutor::class),
            'printing' => $this->printing,
        ]);
        $preSchemaDiffStage->prepare([]);
        $preSchemaDiffStage->commit();
        Register::runPendingRegistrations();
        Register::clearRegisterPhase();
        $modules = Env::getInstance()->getModuleList();
        $no_modules = [];
        $diff_base_path_modules = [];
        $missing_register_files = [];
        foreach ($modules as $module) {
            if (!isset($dependencyModules[$module['name']])) {
                // 检查模块的 register.php 文件是否存在
                $expected_register_file = ($module['base_path'] ?? '') . DS . 'register.php';
                if (file_exists($expected_register_file)) {
                    // register.php 文件存在但无法解析，可能是文件格式问题
                    // 这种情况下，模块实际上是存在的，只是 register.php 无法解析
                    // 我们应该允许继续执行，但给出警告
                    $missing_register_files[$module['name']] = $expected_register_file;
                } else {
                    // register.php 文件不存在，这是真正的"未找到"模块
                    $no_modules[] = $module['name'];
                }
            }
            $dependencyModule = $dependencyModules[$module['name']]??[];
            $moduleBasePath = $module['base_path'] ?? '';
            $dependencyBasePath = $dependencyModule['base_path'] ?? '';
            if ($moduleBasePath != $dependencyBasePath) {
                $diff_base_path_modules[] = $module['name'];
            }
        }
        
        // 如果有 register.php 文件存在但无法解析的模块，输出详细信息
        // 这些模块实际上是存在的，只是 register.php 无法解析，不应该阻止升级
        if (!empty($missing_register_files)) {
            $this->printing->warning(__('以下模块的 register.php 文件存在但无法解析，将尝试手动注册：'));
            foreach ($missing_register_files as $moduleName => $registerFile) {
                $this->printing->warning(__('  - %{1}: %{2}', [$moduleName, $registerFile]));
                // 尝试读取文件内容，帮助调试
                $fileContent = file_get_contents($registerFile);
                if (trim($fileContent) === '') {
                    $this->printing->warning(__('    文件为空，将跳过该模块的自动注册。'));
                } elseif (strpos($fileContent, 'Register::register') === false) {
                    $this->printing->warning(__('    文件中未找到 Register::register() 调用，将跳过该模块的自动注册。'));
                } else {
                    $this->printing->warning(__('    文件中包含 Register::register() 调用，但解析失败，可能是语法错误。将尝试直接执行该文件。'));
                    // 尝试直接执行 register.php 文件
                    try {
                        if (is_file($registerFile)) {
                            require $registerFile;
                            $this->printing->success(__('    ✓ 已成功执行 register.php 文件。'));
                        }
                    } catch (\Exception $e) {
                        $this->printing->warning(__('    执行 register.php 文件时出错：%{1}', [$e->getMessage()]));
                    }
                }
            }
            $this->printing->note(__('这些模块的 register.php 文件无法自动解析，但不会中断升级流程。'));
        }
        
        // 只有真正找不到 register.php 文件的模块才抛出异常
        if ($no_modules) {
            $this->cleanupMissingModuleAclResidues($no_modules);
            $system->exec(PHP_BINARY . ' bin/w cache:clear -f');
            $this->printing->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printing->setup(__('%{modules} 模块未找到(异常卸载)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %{modules}', ['modules' => implode(' ', $no_modules)]));
            // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
            throw new Exception(__('模块检查失败：%{modules} 模块未找到(异常卸载)，请先执行 php bin/w module:remove %{modules} 卸载这些模块', ['modules' => implode(' ', $no_modules)]));
        }
        if ($diff_base_path_modules) {
            $this->cleanupMissingModuleAclResidues($diff_base_path_modules);
            $system->exec(PHP_BINARY . ' bin/w cache:clear -f');
            $this->printing->setup(__('发现网站正在进行搬迁，请再次运行php bin/w setup:upgrade命令！如果还有有问题请运行composer update后再次运行。'));
            $this->printing->setup(__('%{modules} 模块路径不一致(异常搬迁)，如果模块确认需要卸载，请再次执行：php bin/w module:remove %{modules}', ['modules' => implode(' ', $diff_base_path_modules)]));
            // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
            throw new Exception(__('模块检查失败：%{modules} 模块路径不一致(异常搬迁)，请先执行 php bin/w module:remove %{modules} 卸载这些模块', ['modules' => implode(' ', $diff_base_path_modules)]));
        }

        $dependencyModuleNames = array_keys($dependencyModules);
        foreach ($modules as $module) {
            if (!in_array($module['name'], $dependencyModuleNames)) {
                $this->printing->error(__('发现严重错误！请检查 %{1} 模块是否已经被删除，请手动确认并删除 %{2} 中关于此模块的信息！', [$module['name'], Env::path_MODULES_FILE]));
                $this->printing->note(__('输入以下信息选项，确认操作！'));
                $this->printing->note(__('1) 停止执行。手动确认模块信息并处理。【默认】'));
                $this->printing->note(__('2) 继续执行。（可能会出现不可预知的错误）'));
                $anser = $system->input();
                if ($anser == '1' || ($anser != '2')) {
                    $this->printing->setup(__('程序停止运行，请检查问题后继续执行！'));
                    // 抛出异常而不是exit，让外层的try-catch-finally可以正确处理并释放锁
                    throw new Exception(__('用户选择停止执行：模块 %{1} 已被删除，请手动确认并删除 %{2} 中关于此模块的信息', [$module['name'], Env::path_MODULES_FILE]));
                }
                $this->printing->setup(__('你选择了继续执行，可能会出现不可预知的错误。'));
                $total = 3;
                for ($i = 1; $i <= $total; $i++) {
                    echo __("%{1} 秒后程序继续执行 %{2} ...\r", [$total, $i]);
                    // 模拟处理时间
                    usleep(1000000);
                }
            }
        }

        // ========== 使用阶段更新管理器统一管理所有更新 ==========
        // 检查是否指定了部分更新模式
        $doModel = isset($args['model']);
        $doRoute = isset($args['route']);
        $isRouteOnly = $doRoute && !$doModel;

        // 仅更新路由模式（可能带 --module）：使用专用 RouteUpdateService，避免与通用阶段管理逻辑冲突
        if ($isRouteOnly) {
            // 🔧 路由注册会触发 ControllerAttributes 查询 m_acl 等表，必须先提交 SchemaDiff 确保表已存在
            $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $frameworkDbBootstrapStage = ObjectManager::make(FrameworkDbBootstrapStage::class, ['connectionFactory' => $connectionFactory]);
            $frameworkDbBootstrapStage->prepare([]);
            $frameworkDbBootstrapStage->commit();

            /** @var Handle $routeModuleHandle */
            $routeModuleHandle = ObjectManager::getInstance(Handle::class);
            $eavSchemaStage = ObjectManager::make(EavSchemaStage::class, [
                'eventsManager' => ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class),
                'migrationModel' => ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class),
                'printing' => $this->printing,
            ]);
            $eavSchemaStage->prepare([]);
            $eavSchemaStage->commit();

            $schemaDiffStage = ObjectManager::make(SchemaDiffStage::class, [
                'moduleHandle' => $routeModuleHandle,
                'moduleReader' => ObjectManager::getInstance(\Weline\Framework\Module\Config\ModuleFileReader::class),
                'connectionFactory' => $connectionFactory,
                'schemaParser' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaParser::class),
                'dbSchemaReader' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\DbSchemaReader::class),
                'diffEngine' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaDiffEngine::class),
                'executor' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaMigrationExecutor::class),
                'printing' => $this->printing,
            ]);
            $schemaDiffStage->prepare([]);
            $schemaDiffStage->commit();

            /** @var \Weline\Framework\Router\Service\RouteUpdateService $routeService */
            $routeService = ObjectManager::getInstance(\Weline\Framework\Router\Service\RouteUpdateService::class);

            // 从参数中解析需要刷新的模块列表（--module 或 -m）
            $routeModules = $args['module'] ?? $args['m'] ?? [];
            if (is_string($routeModules)) {
                $routeModules = explode(' ', $routeModules);
            }
            $routeModules = array_values(array_filter(array_map('trim', (array)$routeModules)));

            $this->printing->note(__('进入仅更新路由模式，使用 RouteUpdateService 处理路由更新...'), '系统');
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            try {
                $beforeRouteCollectionEventData = [];
                $eventsManager->dispatch('Weline_Framework_Setup::before_route_collection', $beforeRouteCollectionEventData);
            } catch (\Throwable $e) {
                $this->printing->warning(__('菜单预收集失败（可能影响 ACL 断言）：%{1}', [$e->getMessage()]));
            }
            $routeService->updateRoutes($routeModules);
            try {
                $afterRouteCollectionEventData = [];
                $eventsManager->dispatch('Weline_Framework_Setup::after_route_collection', $afterRouteCollectionEventData);
            } catch (\Throwable $e) {
                $this->printing->warning(__('路由收集后 ACL 同步失败：%{1}', [$e->getMessage()]));
            }
            $this->printing->success(__('✓ 路由更新完成（仅路由模式）'));

            // 路由已完成更新，不再进入后续阶段管理器逻辑，直接结束当前方法
            return;
        }

        /**@var Handle $module_handle */
        $module_handle = ObjectManager::getInstance(Handle::class);
        $modules = $module_handle->getModules();
        
        $stageNumber = 1;
        $this->printing->note($stageNumber . '、初始化阶段更新管理器...', '系统');
        /**@var StageUpdateManager $stageManager */
        $stageManager = ObjectManager::getInstance(StageUpdateManager::class);

        // Order 0: Framework 数据库引导（Migration/MigrationBackup 等 bootstrap 表）
        if (!$isRouteOnly) {
            $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $frameworkDbBootstrapStage = ObjectManager::make(FrameworkDbBootstrapStage::class, ['connectionFactory' => $connectionFactory]);
            $stageManager->registerStage($frameworkDbBootstrapStage, 0);
        }

        // 创建各个更新阶段
        $moduleSetupStage = ObjectManager::make(ModuleSetupStage::class, ['moduleHandle' => $module_handle]);
        $stageManager->registerStage($moduleSetupStage, 1);

        // 仅在非仅更新路由模式下创建数据库更新阶段与声明式 Schema Diff 阶段
        $databaseStage = null;
        if (!$isRouteOnly) {
            $eavSchemaStage = ObjectManager::make(EavSchemaStage::class, [
                'eventsManager' => ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class),
                'migrationModel' => ObjectManager::getInstance(\Weline\Framework\Setup\Model\Migration::class),
                'printing' => $this->printing,
            ]);
            $stageManager->registerStage($eavSchemaStage, 2);

            /**@var ModelManager $modelManager */
            $modelManager = ObjectManager::getInstance(ModelManager::class);
            $databaseStage = ObjectManager::make(DatabaseUpdateStage::class, ['modelManager' => $modelManager]);
            $stageManager->registerStage($databaseStage, 3);

            $schemaDiffStage = ObjectManager::make(SchemaDiffStage::class, [
                'moduleHandle' => $module_handle,
                'moduleReader' => ObjectManager::getInstance(\Weline\Framework\Module\Config\ModuleFileReader::class),
                'connectionFactory' => $connectionFactory,
                'schemaParser' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaParser::class),
                'dbSchemaReader' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\DbSchemaReader::class),
                'diffEngine' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaDiffEngine::class),
                'executor' => ObjectManager::getInstance(\Weline\Framework\Database\Schema\SchemaMigrationExecutor::class),
                'printing' => $this->printing,
            ]);
            $stageManager->registerStage($schemaDiffStage, 4);
        }

        /**@var \Weline\Framework\Router\Helper\Data $routerHelper */
        $routerHelper = ObjectManager::getInstance(\Weline\Framework\Router\Helper\Data::class);
        $routeStage = ObjectManager::make(RouteUpdateStage::class, ['routerHelper' => $routerHelper]);
        $stageManager->registerStage($routeStage, 5);

        $fileStage = ObjectManager::make(FileUpdateStage::class);
        $stageManager->registerStage($fileStage, 6);
        
        // ========== 批量收集所有更新任务 ==========
        $stageNumber++;
        $this->printing->note($stageNumber . '、批量收集模块更新任务...', '系统');
        
        // 收集模块安装/升级任务
        /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
        $moduleHelper = ObjectManager::getInstance(\Weline\Framework\Module\Helper\Data::class);
        foreach ($modules as $module_name => $module) {
            if ($argsModule and !in_array($module_name, $argsModule)) {
                continue;
            }
            
            $moduleObj = new Module($module);
            
            if (isset($module['upgrading']) and $module['upgrading']) {
                $moduleSetupStage->addUpgradeTask($moduleObj);
                $this->printing->note(__('收集模块升级任务：%{1}', [$module_name]));
            }
            
            if (isset($module['installing']) and $module['installing']) {
                $moduleSetupStage->addInstallTask($moduleObj);
                $this->printing->note(__('收集模块安装任务：%{1}', [$module_name]));
            }
            
            // 开发环境：已安装且无升级的模块，强制执行 Install.php 的 setup()，使 Setup 内初始化/种子数据可被更新
            if (defined('DEV') && DEV && !isset($module['installing']) && !isset($module['upgrading'])
                && !$moduleHelper->isDisabled($modules, $module_name)) {
                $moduleObj->setData('dev_force_run_install', true);
                $moduleSetupStage->addInstallTask($moduleObj);
                $this->printing->note(__('收集模块开发环境重跑安装脚本：%{1}', [$module_name]));
            }
        }
        
        // 收集数据库更新任务（仅在非仅更新路由模式下执行）
        // 如果是仅更新路由模式，跳过数据库更新任务收集
        if (!$isRouteOnly && $databaseStage !== null) {
            $this->printing->note(__('   - 批量收集数据库更新任务...'));
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                
                $moduleObj = new Module($module);
                $setupContext = ObjectManager::make(SetupContext::class, [
                    'module_name' => $moduleObj->getName(),
                    'module_version' => $moduleObj->getVersion(),
                    'module_description' => $moduleObj->getDescription()
                ], '__construct');
                
                // 根据模块状态决定更新类型
                /**@var \Weline\Framework\Module\Helper\Data $moduleHelper */
                $moduleHelper = ObjectManager::getInstance(\Weline\Framework\Module\Helper\Data::class);
                $oldModules = $module_handle->getModules(); // 获取旧模块列表（在注册前）
                
                if (isset($module['installing']) and $module['installing']) {
                    // 新安装的模块
                    $databaseStage->addUpdateTask($moduleObj, $setupContext, 'install');
                    if (DEV) {
                        $databaseStage->addUpdateTask($moduleObj, $setupContext, 'setup');
                    }
                } elseif (isset($module['upgrading']) and $module['upgrading']) {
                    // 升级的模块
                    $old_version = $moduleHelper->isInstalled($oldModules, $moduleObj->getName()) 
                        ? ($oldModules[$moduleObj->getName()]['version'] ?? '1.0.0')
                        : '1.0.0';
                    if ($moduleHelper->isUpgrade($old_version, $moduleObj->getVersion())) {
                        $databaseStage->addUpdateTask($moduleObj, $setupContext, 'upgrade');
                    }
                    if (DEV) {
                        $databaseStage->addUpdateTask($moduleObj, $setupContext, 'setup');
                    }
                } else {
                    // 已存在的模块，只执行 setup（开发模式）
                    if (DEV) {
                        $databaseStage->addUpdateTask($moduleObj, $setupContext, 'setup');
                    }
                }
            }
        } else {
            $this->printing->note(__('   - 仅更新路由模式，跳过数据库更新任务收集'));
        }
        
        // 仅在将提交 route_update 时准备并收集路由；单独指定其他阶段（如 schema_diff）时跳过，避免 enableBatchMode 清空缓冲后不 flush 导致路由丢失
        $willCommitRoute = $shouldCommitStage(self::STAGE_ROUTE_UPDATE);
        if ($willCommitRoute) {
            $routeStage->prepare([
                'modules_to_clear' => $argsModule ?: []
            ]);
        }
        
        // 🔧 先提交 SchemaDiff，确保表已创建，再收集路由（registerRoute 会触发 ControllerAttributes 查询 m_acl 等表；--route 模式同样需要表已存在）
        if ($databaseStage !== null) {
            $frameworkDbBootstrapStage = $stageManager->getStage('framework_db_bootstrap');
            $eavSchemaStage = $stageManager->getStage('eav_schema');
            $schemaDiffStage = $stageManager->getStage('schema_diff');
            if ($frameworkDbBootstrapStage && !$frameworkDbBootstrapStage->isCommitted()) {
                $frameworkDbBootstrapStage->prepare([]);
                if ($frameworkDbBootstrapStage->isPrepared()) {
                    $this->printing->note(__('   - 提前提交 Framework 数据库引导（供 SchemaDiff 使用）...'));
                    $frameworkDbBootstrapStage->commit();
                }
            }
            if ($eavSchemaStage && !$eavSchemaStage->isCommitted()) {
                $eavSchemaStage->prepare([]);
                if ($eavSchemaStage->isPrepared()) {
                    $this->printing->note(__('   - 提前提交 EavSchema...'));
                    $eavSchemaStage->commit();
                }
            }
            if ($schemaDiffStage && !$schemaDiffStage->isCommitted()) {
                $schemaDiffStage->prepare([]);
                if ($schemaDiffStage->isPrepared()) {
                    $this->printing->note(__('   - 提前提交 SchemaDiff（确保表在路由收集前已创建）...'));
                    $schemaDiffStage->commit();
                }
            }
        }
        
        // 先收集菜单（MenuCollector diff 写入 weline_acl type=menus），确保 ControllerAttributes 断言时 parent_source 已存在
        if ($willCommitRoute) {
            $this->printing->note(__('   - 收集菜单（先于路由，供 ACL 断言校验 parent_source）...'));
            try {
                $eventsManager = ObjectManager::getInstance(EventsManager::class);
                $menuEventData = [];
                $eventsManager->dispatch('Weline_Framework_Setup::before_route_collection', $menuEventData);
            } catch (\Throwable $e) {
                $this->printing->warning(__('菜单预收集失败（可能影响 ACL 断言）：%{1}', [$e->getMessage()]));
            }
        }
        
        // 收集路由注册任务（仅在将提交 route_update 时执行，避免污染路由缓冲）
        if ($willCommitRoute) {
            $this->printing->note(__('   - 批量收集路由注册任务...'));
            foreach ($modules as $module_name => $module) {
                if ($argsModule and !in_array($module_name, $argsModule)) {
                    continue;
                }
                try {
                    $module_handle->registerRoute(new Module($module));
                } catch (Exception $exception) {
                    $this->printing->error(__('模块 %{1} 路由注册失败：%{2}', [$module_name, $exception->getMessage()]));
                    $routeStage->rollback();
                    throw new Exception(__('模块 %{1} 路由注册失败：%{2}', [$module_name, $exception->getMessage()]));
                }
            }
            // 路由收集完成后做 ACL diff（清理已卸载模块的 type=pc 等）
            try {
                $eventsManager = ObjectManager::getInstance(EventsManager::class);
                $afterRouteCollectionEventData = [];
                $eventsManager->dispatch('Weline_Framework_Setup::after_route_collection', $afterRouteCollectionEventData);
            } catch (\Throwable $e) {
                $this->printing->warning(__('路由收集后 ACL 同步失败：%{1}', [$e->getMessage()]));
            }
        }
        
        // ========== 准备所有阶段 ==========
        $stageNumber++;
        $this->printing->note($stageNumber . '、准备所有更新阶段...', '系统');
        try {
            $stageManager->prepareAll(['skip_route_stage' => !$willCommitRoute]);
            $this->printing->success(__('✓ 所有阶段准备完成'));
        } catch (Exception $e) {
            $this->printing->error(__('阶段准备失败：%{1}', [$e->getMessage()]));
            throw $e;
        }
        
        // ========== 验证所有阶段 ==========
        $stageNumber++;
        $this->printing->note($stageNumber . '、验证所有更新阶段...', '系统');
        try {
            $stageManager->validateAll();
            $this->printing->success(__('✓ 所有阶段验证通过'));
        } catch (Exception $e) {
            $this->printing->error(__('阶段验证失败：%{1}', [$e->getMessage()]));
            // 回滚所有已准备的阶段
            foreach ($stageManager->getStatus() as $stageName => $status) {
                $stage = $stageManager->getStage($stageName);
                if ($stage && $stage->isPrepared()) {
                    $stage->rollback();
                }
            }
            throw $e;
        }
        
        // ========== 提交所有阶段（分步执行，支持动态收集） ==========
        $stageNumber++;
        $this->printing->note($stageNumber . '、提交所有更新阶段（批量执行）...', '系统');
        try {
            // 第一步：先提交 SchemaDiff 等数据库阶段，确保表已创建，再执行模块 Install/Upgrade（种子数据依赖表已存在）
            if (!$isRouteOnly && $databaseStage !== null) {
                $frameworkDbBootstrapStage = $stageManager->getStage('framework_db_bootstrap');
                $moduleManagerBootstrapStage = $stageManager->getStage('module_manager_bootstrap');
                $needBootstrapTables = $shouldCommitStage(self::STAGE_EAV_SCHEMA) || $shouldCommitStage(self::STAGE_SCHEMA_DIFF) || $shouldCommitStage(self::STAGE_DATABASE_UPDATE);
                if ($needBootstrapTables && $frameworkDbBootstrapStage && $frameworkDbBootstrapStage->isPrepared() && !$frameworkDbBootstrapStage->isCommitted()) {
                    $this->printing->note(__('   - 提交 Framework 数据库引导阶段（%{1}）（依赖阶段，先执行）...', [self::STAGE_FRAMEWORK_DB_BOOTSTRAP]));
                    $frameworkDbBootstrapStage->commit();
                } elseif ($shouldCommitStage(self::STAGE_FRAMEWORK_DB_BOOTSTRAP) && $frameworkDbBootstrapStage && $frameworkDbBootstrapStage->isPrepared()) {
                    $this->printing->note(__('   - 提交 Framework 数据库引导阶段（%{1}）...', [self::STAGE_FRAMEWORK_DB_BOOTSTRAP]));
                    $frameworkDbBootstrapStage->commit();
                } elseif ($stageFilter !== null && !$shouldCommitStage(self::STAGE_FRAMEWORK_DB_BOOTSTRAP) && !$needBootstrapTables) {
                    $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_FRAMEWORK_DB_BOOTSTRAP]));
                }
                if ($needBootstrapTables && $moduleManagerBootstrapStage && $moduleManagerBootstrapStage->isPrepared() && !$moduleManagerBootstrapStage->isCommitted()) {
                    $this->printing->note(__('   - 提交 ModuleManager 引导阶段（%{1}）（依赖阶段，先执行）...', [self::STAGE_MODULE_MANAGER_BOOTSTRAP]));
                    $moduleManagerBootstrapStage->commit();
                } elseif ($shouldCommitStage(self::STAGE_MODULE_MANAGER_BOOTSTRAP) && $moduleManagerBootstrapStage && $moduleManagerBootstrapStage->isPrepared()) {
                    $this->printing->note(__('   - 提交 ModuleManager 引导阶段（%{1}）...', [self::STAGE_MODULE_MANAGER_BOOTSTRAP]));
                    $moduleManagerBootstrapStage->commit();
                } elseif ($stageFilter !== null && !$shouldCommitStage(self::STAGE_MODULE_MANAGER_BOOTSTRAP)) {
                    $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_MODULE_MANAGER_BOOTSTRAP]));
                }
                $eavSchemaStage = $stageManager->getStage('eav_schema');
                if ($shouldCommitStage(self::STAGE_EAV_SCHEMA) && $eavSchemaStage && $eavSchemaStage->isPrepared()) {
                    $this->printing->note(__('   - 提交 EavSchema 阶段（%{1}）...', [self::STAGE_EAV_SCHEMA]));
                    $eavSchemaStage->commit();
                } elseif ($stageFilter !== null && !$shouldCommitStage(self::STAGE_EAV_SCHEMA)) {
                    $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_EAV_SCHEMA]));
                }
                $schemaDiffStage = $stageManager->getStage('schema_diff');
                if ($shouldCommitStage(self::STAGE_SCHEMA_DIFF) && $schemaDiffStage && $schemaDiffStage->isPrepared()) {
                    $this->printing->note(__('   - 提交 SchemaDiff 阶段（%{1}）（表结构先于 Install 种子数据）...', [self::STAGE_SCHEMA_DIFF]));
                    $schemaDiffStage->commit();
                } elseif ($stageFilter !== null && !$shouldCommitStage(self::STAGE_SCHEMA_DIFF)) {
                    $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_SCHEMA_DIFF]));
                }
            }

            // 第二步：提交模块安装/升级阶段（此时表已存在，Install 种子数据可正常写入）
            if ($shouldCommitStage(self::STAGE_MODULE_SETUP)) {
                $this->printing->note(__('   - 提交模块安装/升级阶段（%{1}）...', [self::STAGE_MODULE_SETUP]));
                $moduleSetupStage->commit();
            } else {
                $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_MODULE_SETUP]));
            }

            // 检查是否有模块被安装或升级（仅当执行了 module_setup 时）
            if ($shouldCommitStage(self::STAGE_MODULE_SETUP) && $moduleSetupStage->hasModuleInstalledOrUpgraded()) {
                $this->hasModuleInstalledOrUpgraded = true;
                
                // 模块安装/升级后，需要重新收集可能产生的新任务
                $this->printing->note(__('检测到模块安装/升级，重新收集后续任务...'));
                
                // 重新加载模块列表（可能有新模块被安装，或者模块状态已更新）
                $module_handle = ObjectManager::getInstance(Handle::class);
                $updatedModules = $module_handle->getModules();
                
                // 重新收集数据库更新任务（检查是否有遗漏的任务）
                $this->printing->note(__('   - 重新收集数据库更新任务...'));
                foreach ($updatedModules as $module_name => $module) {
                    if ($argsModule and !in_array($module_name, $argsModule)) {
                        continue;
                    }
                    
                    $moduleObj = new Module($module);
                    $setupContext = ObjectManager::make(SetupContext::class, [
                        'module_name' => $moduleObj->getName(),
                        'module_version' => $moduleObj->getVersion(),
                        'module_description' => $moduleObj->getDescription()
                    ], '__construct');
                    
                    // 检查该模块是否已有数据库任务
                    $hasDatabaseTask = false;
                    try {
                        $reflection = new \ReflectionClass($databaseStage);
                        $property = $reflection->getProperty('updateTasks');
                        $property->setAccessible(true);
                        $existingTasks = $property->getValue($databaseStage);
                        
                        foreach ($existingTasks as $task) {
                            if ($task['module']->getName() === $moduleObj->getName()) {
                                $hasDatabaseTask = true;
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // 反射失败，继续执行
                    }
                    
                    // 注意：模块安装时，setupInstall 内部会调用 modelManager->update(install)
                    // 所以数据库更新任务已经在模块安装阶段执行了
                    // 这里主要是检查是否有新注册的模块（依赖模块）需要数据库初始化
                    // 新注册的模块不会有 installing 标志，因为它们是在安装过程中被注册的
                    // 所以这里我们主要检查是否有遗漏的模块
                }
                
                // 重新收集路由注册任务（仅当将提交 route_update 时执行）
                if ($willCommitRoute) {
                    $this->printing->note(__('   - 重新收集路由注册任务...'));
                    foreach ($updatedModules as $module_name => $module) {
                        if ($argsModule and !in_array($module_name, $argsModule)) {
                            continue;
                        }
                        try {
                            // 检查路由是否已经注册（通过检查批量缓存）
                            $needsRoute = true;
                            foreach (Env::router_files_PATH as $path) {
                                $routers = $routerHelper->getBatchRouters($path);
                                foreach ($routers as $router) {
                                    $routerModule = '';
                                    if (is_array($router)) {
                                        if (isset($router['module'])) {
                                            $routerModule = $router['module'];
                                        } elseif (isset($router['rule']) && is_array($router['rule']) && isset($router['rule']['module'])) {
                                            $routerModule = $router['rule']['module'];
                                        }
                                    }
                                    if ($routerModule === $module_name) {
                                        $needsRoute = false;
                                        break 2;
                                    }
                                }
                            }
                            if ($needsRoute) {
                                $this->printing->note(__('   - 为模块 %{1} 注册路由...', [$module_name]));
                                $module_handle->registerRoute(new Module($module));
                            }
                        } catch (Exception $exception) {
                            $this->printing->warning(__('模块 %{1} 路由重新注册失败：%{2}，继续执行...', [
                                $module_name,
                                $exception->getMessage()
                            ]));
                        }
                    }
                }
                
                // 重新验证已更新的阶段（确保新添加的任务有效）
                $this->printing->note(__('   - 重新验证更新的阶段...'));
                if (!$isRouteOnly && $databaseStage !== null) {
                    if (!$databaseStage->validate()) {
                        $status = $databaseStage->getStatus();
                        $errors = $status['errors'] ?? [];
                        $errorMsg = !empty($errors) ? implode('; ', $errors) : __('验证失败');
                        throw new Exception(__('数据库更新阶段验证失败：%{1}', [$errorMsg]));
                    }
                }
                if ($willCommitRoute && !$routeStage->validate()) {
                    $status = $routeStage->getStatus();
                    $errors = $status['errors'] ?? [];
                    $errorMsg = !empty($errors) ? implode('; ', $errors) : __('验证失败');
                    throw new Exception(__('路由更新阶段验证失败：%{1}', [$errorMsg]));
                }
            }
            
            // 第三步：提交数据库更新阶段（framework/eav/schema_diff 已在第一步提交）
            if (!$isRouteOnly && $databaseStage !== null) {
                if ($shouldCommitStage(self::STAGE_DATABASE_UPDATE)) {
                    $this->printing->note(__('   - 提交数据库更新阶段（%{1}）...', [self::STAGE_DATABASE_UPDATE]));
                    $databaseStage->commit();
                } else {
                    $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_DATABASE_UPDATE]));
                }
            } else {
                if (!$isRouteOnly) {
                    $this->printing->note(__('   - 仅更新路由模式，跳过数据库更新阶段提交'));
                }
            }
            
            // 第四步：提交路由更新阶段
            if ($shouldCommitStage(self::STAGE_ROUTE_UPDATE)) {
                $this->printing->note(__('   - 提交路由更新阶段（%{1}）...', [self::STAGE_ROUTE_UPDATE]));
                $routeStage->commit();
            } else {
                $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_ROUTE_UPDATE]));
            }
            
            // 第五步：提交文件更新阶段
            if ($shouldCommitStage(self::STAGE_FILE_UPDATE)) {
                $this->printing->note(__('   - 提交文件更新阶段（%{1}）...', [self::STAGE_FILE_UPDATE]));
                $fileStage->commit();
            } else {
                $this->printing->note(__('   - 跳过阶段 %{1}', [self::STAGE_FILE_UPDATE]));
            }
            // 一次性重载 Env（含 modules.php），避免本进程后续 getModuleList 仍用旧缓存导致 base_path 等错误
            Env::getInstance()->reload();

            $this->printing->success(__('✓ 所有阶段更新完成！'));
        } catch (Exception $e) {
            $this->printing->error(__('阶段提交失败：%{1}', [$e->getMessage()]));
            throw $e;
        }
        
        if ($argsModule) {
            $this->printing->note(__('指定模块 %{1} 更新完毕！', [implode(', ', $argsModule)]));
        } else {
            $this->printing->note('模块更新完毕！');
        }
        $stageNumber++;
        $this->printing->note($stageNumber . '、收集模块信息', '系统');
        # 加载module中的助手函数
        $modules = Env::getInstance()->getActiveModules();
        $function_files_content = '';
        
        // 文件头部：必须包含 <?php 和 declare(strict_types=1);
        $file_header = "<?php" . PHP_EOL . "declare(strict_types=1);" . PHP_EOL;
        
        // 如果指定了模块，先读取现有文件内容（保留其他模块的函数）
        $existing_content = '';
        if ($argsModule && is_file(Env::path_FUNCTIONS_FILE)) {
            $existing_content = file_get_contents(Env::path_FUNCTIONS_FILE);
            // 移除文件头部（如果存在），保留实际内容
            $existing_content = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/i', '', $existing_content);
            // 尝试移除指定模块的旧函数（通过注释标记识别）
            // 注意：这是一个简化的实现，假设函数文件中有模块标记注释
            foreach ($argsModule as $moduleName) {
                // 移除以 "// Module: $moduleName" 开头的块直到下一个 "// Module:" 或文件结束
                $pattern = '/\/\/\s*Module:\s*' . preg_quote($moduleName, '/') . '.*?(?=\/\/\s*Module:|$)/s';
                $existing_content = preg_replace($pattern, '', $existing_content);
            }
            // 清理多余的空行
            $existing_content = preg_replace('/\n{3,}/', "\n\n", $existing_content);
            $existing_content = trim($existing_content);
        }
        
        foreach ($modules as $module) {
            if ($argsModule and !in_array($module['name'], $argsModule)) {
                continue;
            }
            $global_file_pattern = $module['base_path'] . 'Global' . DS . '*.php';
            $global_files = glob($global_file_pattern);
            if (!empty($global_files)) {
                // 添加模块标记注释（放在 declare 之后）
                $function_files_content .= PHP_EOL . '// Module: ' . $module['name'] . PHP_EOL;
                foreach ($global_files as $global_file) {
                    # 读取文件内容 去除注释以及每个文件末尾的 '\?\>'结束符
                    $file_content = file_get_contents($global_file);
                    // 移除文件中的 <?php 和 declare 语句（如果存在），因为已经在文件头部统一处理
                    $file_content = preg_replace('/^<\?php\s*/i', '', $file_content);
                    $file_content = preg_replace('/declare\(strict_types=1\);\s*/i', '', $file_content);
                    $file_content = str_replace('?>', '', $file_content);
                    $function_files_content .= trim($file_content) . PHP_EOL;
                }
            }
        }
        
        // 使用阶段更新管理器写入文件（确保原子性）
        /**@var FileUpdateStage $fileStage */
        $fileStage = ObjectManager::make(FileUpdateStage::class);
        
        // 准备函数文件内容
        if ($argsModule && $function_files_content) {
            # 合并现有内容和新内容，确保文件头部正确
            $final_content = $file_header;
            if ($existing_content) {
                $final_content .= $existing_content . PHP_EOL;
            }
            $final_content .= trim($function_files_content);
            $fileStage->addFunctionsFile($final_content);
        } elseif (!$argsModule) {
            # 写入文件（完整升级，覆盖所有内容），确保文件头部正确
            $final_content = $file_header;
            if ($function_files_content) {
                $final_content .= trim($function_files_content);
            }
            $fileStage->addFunctionsFile($final_content);
        }
        
        // 准备并提交文件更新（只有在有内容需要写入时才执行）
        if (!empty($function_files_content)) {
            try {
                $this->printing->note(__('   - 准备函数文件更新...'));
                $fileStage->prepare();
                if ($fileStage->validate()) {
                    $this->printing->note(__('   - 写入函数文件：%{1}', [Env::path_FUNCTIONS_FILE]));
                    $fileStage->commit();
                    $this->printing->success(__('✓ 函数文件写入完成！'));
                } else {
                    $status = $fileStage->getStatus();
                    $errors = $status['errors'] ?? [];
                    $errorMsg = !empty($errors) ? implode('; ', $errors) : __('验证失败');
                    throw new Exception(__('函数文件更新验证失败：%{1}', [$errorMsg]));
                }
            } catch (Exception $exception) {
                $this->printing->error(__('函数文件写入失败：%{1}', [$exception->getMessage()]));
                $fileStage->rollback();
                throw $exception;
            }
        }

        $stageNumber++;

        // 清理其他
        $this->printing->note($stageNumber . '、触发模块升级后事件...', '系统');
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        // 将本次参与升级的模块名称传递给事件，便于观察者按模块增量处理
        $eventModules = $args['module'] ?? $args['m'] ?? [];
        if (is_string($eventModules)) {
            $eventModules = explode(' ', $eventModules);
        }
        
        // 区分启用和禁用的模块
        $enabledModules = [];
        $disabledModules = [];
        $env = Env::getInstance();
        
        if (empty($eventModules)) {
            // 如果没有指定模块，获取所有模块并分类
            $allModules = $env->getModuleList();
            foreach ($allModules as $moduleName => $moduleConfig) {
                if (!empty($moduleConfig['status'])) {
                    $enabledModules[] = $moduleName;
                } else {
                    $disabledModules[] = $moduleName;
                }
            }
        } else {
            // 如果指定了模块，检查每个模块的状态
            foreach ($eventModules as $moduleName) {
                if ($env->getModuleStatus($moduleName)) {
                    $enabledModules[] = $moduleName;
                } else {
                    $disabledModules[] = $moduleName;
                }
            }
        }
        
        // 先处理启用的模块（正常更新）
        if (!empty($enabledModules)) {
            $this->printing->note(__('处理已启用模块的升级后事件：%{1}', [implode(', ', $enabledModules)]));
            $moduleEventData = ['modules' => $enabledModules];
            $eventsManager->dispatch('Weline_Framework_Module::module_upgrade', $moduleEventData);
        }
        
        // 最后处理禁用的模块（仅做清理，不收集菜单等）
        if (!empty($disabledModules)) {
            $this->printing->note(__('跳过已禁用模块的菜单收集：%{1}', [implode(', ', $disabledModules)]));
            // 禁用的模块不触发菜单收集等操作，但可以触发其他清理操作
            // 如果需要，可以在这里添加禁用模块的特殊处理逻辑
        }
        
        // 生成 modules.json 用于 E2E 测试用例收集
        $stageNumber++;
        $this->printing->note($stageNumber . '、生成模块信息文件...', '系统');
        $this->generateModulesJson();
    }
    
    /**
     * 生成 modules.json 文件，用于 E2E 测试用例收集
     * 
     * @return void
     */
    private function generateModulesJson(): void
    {
        try {
            $this->printing->note(__('生成 modules.json 用于 E2E 测试用例收集...'));
            
            // 获取所有模块信息
            $modules = Env::getInstance()->getModuleList();
            
            // 构建 modules.json 数据结构
            $modulesData = [
                'generated_at' => date('c'), // ISO 8601 格式
                'modules' => []
            ];
            
            // 遍历所有模块，检查是否有测试目录
            foreach ($modules as $moduleName => $module) {
                $basePath = $module['base_path'] ?? '';
                if (empty($basePath)) {
                    continue;
                }
                
                // 检查测试目录是否存在
                $testPath = $basePath . DS . 'test' . DS . 'e2e';
                $hasTests = is_dir($testPath);
                
                // 转换为相对路径（统一使用正斜杠，兼容 Windows 和 Linux）
                $relativeBasePath = str_replace([BP . DS, DS], ['', '/'], $basePath);
                $relativeTestPath = $hasTests ? str_replace([BP . DS, DS], ['', '/'], $testPath) : null;
                
                // 移除末尾的斜杠
                $relativeBasePath = rtrim($relativeBasePath, '/');
                if ($relativeTestPath) {
                    $relativeTestPath = rtrim($relativeTestPath, '/');
                }
                
                $modulesData['modules'][$moduleName] = [
                    'name' => $moduleName,
                    'base_path' => $relativeBasePath,
                    'test_path' => $relativeTestPath,
                    'status' => $module['status'] ?? false,
                    'version' => $module['version'] ?? '1.0.0',
                    'has_tests' => $hasTests
                ];
            }
            
            // 确保 tests/e2e 目录存在
            $e2eDir = BP . 'tests' . DS . 'e2e';
            if (!is_dir($e2eDir)) {
                mkdir($e2eDir, 0755, true);
            }
            
            // 写入 modules.json
            $jsonFile = $e2eDir . DS . 'modules.json';
            $jsonContent = json_encode($modulesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($jsonFile, $jsonContent);
            
            $this->printing->success(__('✓ modules.json 已生成: %{1}', [$jsonFile]));
        } catch (\Exception $e) {
            // 生成失败不影响系统升级，只记录警告
            w_log_warning(__('生成 modules.json 失败: %{1}', [$e->getMessage()]), [], 'modules_json.log');
            $this->printing->warning(__('生成 modules.json 时发生错误：%{1}，但将继续执行。', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取锁文件路径
     * @return string
     */
    private function getLockFile(): string
    {
        $lockDir = BP . 'var' . DS . 'process';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        return $lockDir . DS . 'setup_upgrade.lock';
    }
    
    /**
     * 尝试获取文件锁
     * @param string $lockFile 锁文件路径
     * @return resource|null 返回文件句柄，如果获取失败返回null
     */
    private function acquireLock(string $lockFile)
    {
        # 检查锁文件是否存在，如果存在则检查进程是否还在运行
        if (file_exists($lockFile)) {
            $lockInfo = $this->readLockInfo($lockFile);
            if ($lockInfo !== null && isset($lockInfo['pid'])) {
                # 检查进程是否还在运行
                if (!$this->isProcessRunning($lockInfo['pid'])) {
                    # 进程已不存在，删除旧的锁文件
                    @unlink($lockFile);
                }
            }
        }
        
        # 打开锁文件（如果不存在则创建）
        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            return null;
        }
        
        # 尝试获取排他锁（非阻塞模式）
        # LOCK_EX | LOCK_NB: 排他锁 + 非阻塞
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            # 写入当前进程ID和时间戳
            ftruncate($handle, 0);
            fwrite($handle, json_encode([
                'pid' => getmypid(),
                'time' => date('Y-m-d H:i:s'),
                'command' => 'setup:upgrade'
            ], JSON_UNESCAPED_UNICODE));
            fflush($handle);
            return $handle;
        }
        
        # 获取锁失败，关闭文件句柄
        fclose($handle);
        return null;
    }
    
    /**
     * 读取锁文件信息
     * @param string $lockFile 锁文件路径
     * @return array|null
     */
    private function readLockInfo(string $lockFile): ?array
    {
        if (!file_exists($lockFile)) {
            return null;
        }
        
        $content = @file_get_contents($lockFile);
        if ($content === false || empty(trim($content))) {
            return null;
        }
        
        $info = @json_decode($content, true);
        return is_array($info) ? $info : null;
    }
    
    /**
     * 检查进程是否还在运行
     * @param int $pid 进程ID
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if (IS_WIN) {
            # Windows 系统：使用 tasklist 命令检查进程
            $output = [];
            $returnVar = 0;
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                # 检查输出中是否包含进程ID
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        return true;
                    }
                }
            }
            return false;
        } else {
            # Linux/Unix 系统：使用 kill -0 检查进程
            return posix_kill($pid, 0);
        }
    }
    
    /**
     * 释放文件锁
     * @param resource|null $handle 文件句柄
     * @param string $lockFile 锁文件路径
     */
    private function releaseLock($handle, string $lockFile): void
    {
        if ($handle !== null && is_resource($handle)) {
            # 释放锁
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        
        # 无论文件句柄是否有效，都尝试删除锁文件
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * 获取标识符文件路径
     * 
     * @return string
     */
    private function getRecollectFlagFile(): string
    {
        $processDir = BP . 'var' . DS . 'process';
        if (!is_dir($processDir)) {
            mkdir($processDir, 0755, true);
        }
        return $processDir . DS . 'setup_upgrade_recollect.flag';
    }
    
    /**
     * 创建标识符文件，标记需要再次收集
     * 在升级开始时创建，升级过程中如果有新模块安装，会保持这个标识符
     * 
     * @return void
     */
    private function createRecollectFlag(): void
    {
        if (empty($this->recollectFlagFile)) {
            $this->recollectFlagFile = $this->getRecollectFlagFile();
        }
        
        // 记录升级开始时的模块列表
        $modulesBefore = $this->getModuleList();
        $flagData = [
            'created_at' => date('Y-m-d H:i:s'),
            'modules_before' => $modulesBefore,
            'need_recollect' => true
        ];
        
        file_put_contents($this->recollectFlagFile, json_encode($flagData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    /**
     * 检查是否需要再次收集，如果需要则执行收集
     * 
     * @return void
     */
    private function checkAndRecollectIfNeeded(): void
    {
        if (empty($this->recollectFlagFile)) {
            $this->recollectFlagFile = $this->getRecollectFlagFile();
        }
        
        // 检查标识符文件是否存在
        if (!file_exists($this->recollectFlagFile)) {
            return;
        }
        
        // 读取标识符文件
        $flagContent = @file_get_contents($this->recollectFlagFile);
        if ($flagContent === false) {
            return;
        }
        
        $flagData = @json_decode($flagContent, true);
        if (!is_array($flagData) || !isset($flagData['need_recollect']) || !$flagData['need_recollect']) {
            // 不需要再次收集，删除标识符文件
            @unlink($this->recollectFlagFile);
            return;
        }
        
        // 获取升级后的模块列表
        $modulesAfter = $this->getModuleList();
        $modulesBefore = $flagData['modules_before'] ?? [];
        
        // 比较模块列表，检查是否有新模块
        $newModules = array_diff($modulesAfter, $modulesBefore);
        
        if (!empty($newModules)) {
            $this->printing->note(__('检测到新安装的模块：%{1}，正在重新收集注册表信息...', [implode(', ', $newModules)]));
            $this->recollectRegistryAfterModuleChange();
            $this->printing->success(__('✓ 信息收集完成。'));
        } else {
            // 没有新模块，但标识符文件存在，说明可能是从上次异常恢复
            // 这种情况下，我们仍然需要删除标识符文件，因为升级已经完成
            $this->printing->note(__('未检测到新安装的模块，跳过信息收集。'));
        }
        
        // 收集完成（无论是否有新模块），删除标识符文件
        @unlink($this->recollectFlagFile);
    }
    
    /**
     * 获取当前模块列表
     * 
     * @return array 模块名数组
     */
    private function getModuleList(): array
    {
        try {
            $env = Env::getInstance();
            $modules = $env->getModuleList();
            return array_keys($modules);
        } catch (\Exception $e) {
            // 如果获取模块列表失败，返回空数组
            return [];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '框架代码刷新。';
    }
    
    /**
     * 执行热更新模式
     * 
     * 向运行中的 WLS Worker 发送 SIGUSR1 信号，触发热重载
     */
    private function executeHotReload(): void
    {
        $this->printing->note(__('正在执行热更新...'));
        
        // 读取服务器配置
        $serverConfig = Env::getInstance()->getConfig('server');
        if (empty($serverConfig) || empty($serverConfig['instances'])) {
            $this->printing->warning(__('未检测到运行中的 WLS 服务器实例。'));
            $this->printing->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        
        $reloadCount = 0;
        
        foreach ($serverConfig['instances'] as $name => $instance) {
            if (empty($instance['pid'])) {
                continue;
            }
            
            $pid = (int) $instance['pid'];
            
            // 检查进程是否存在
            if (!$this->isProcessRunning($pid)) {
                $this->printing->warning(__('实例 %{1} 的主进程 (PID: %{2}) 未运行', [$name, $pid]));
                continue;
            }
            
            // 发送 SIGUSR1 信号（仅 Linux/Mac）
            if (\function_exists('posix_kill')) {
                if (\posix_kill($pid, SIGUSR1)) {
                    $this->printing->success(__('已向实例 %{1} (PID: %{2}) 发送热重载信号', [$name, $pid]));
                    $reloadCount++;
                } else {
                    $this->printing->error(__('无法向实例 %{1} 发送信号', [$name]));
                }
            } else {
                // Windows 平台暂不支持信号
                $this->printing->warning(__('Windows 平台暂不支持热更新，请重启服务器：php bin/w server:start -r'));
            }
        }
        
        if ($reloadCount > 0) {
            $this->printing->success(__('热更新信号已发送给 %{1} 个实例', [$reloadCount]));
        } else {
            $this->printing->warning(__('没有可用的 WLS 实例接收热更新信号'));
        }
    }
    
    /**
     * 通知 WLS 服务器热重载
     */
    private function notifyWlsReload(): void
    {
        // 检查是否有运行中的 WLS 服务器
        $serverConfig = Env::getInstance()->getConfig('server');
        if (empty($serverConfig) || empty($serverConfig['instances'])) {
            return;
        }
        
        $hasRunning = false;
        foreach ($serverConfig['instances'] as $instance) {
            if (!empty($instance['pid']) && $this->isProcessRunning((int) $instance['pid'])) {
                $hasRunning = true;
                break;
            }
        }
        
        if ($hasRunning) {
            $this->printing->note(__('检测到运行中的 WLS 服务器，发送热重载信号...'));
            $this->executeHotReload();
        }
    }
    
    public function help(): array|string
    {
        // 基于tip的默认help实现
        $stageCodes = implode(', ', self::STAGE_CODES_ORDERED);
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'setup:upgrade',
            '升级模块系统，包括数据库模型、路由等',
            [
                '--model' => '仅升级数据库模型',
                '--route' => '仅升级路由',
                '-m, --module=<模块名>' => '升级指定模块（例如：Weline_Demo）',
                '--stage=<code>[,code...]' => __('只运行指定阶段（跳过其他阶段，便于单阶段测试）。阶段 code：%{1}', [$stageCodes]),
                '--hot' => '热更新模式，通知运行中的 WLS 服务器重载',
                '-s, --skip-env-check' => '跳过环境依赖检测',
                '-f, --force' => '强制升级（跳过环境依赖检测）',
                '--skip-reflection-compile, --skip-reflect' => __('跳过反射元数据与编译型工厂生成（可事后执行 reflection:compile）'),
                '--skip-background-optimize' => __('禁用后台优化任务，改为同步执行（等待缓存生成完成）'),
                '--sync' => __('同上，--skip-background-optimize 的简写'),
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '升级所有模块' => 'php bin/w setup:upgrade',
                '仅升级数据库模型' => 'php bin/w setup:upgrade --model',
                '仅升级路由' => 'php bin/w setup:upgrade --route',
                __('仅运行 schema_diff 阶段（测试声明式表）') => 'php bin/w setup:upgrade --stage=schema_diff',
                __('仅运行 framework_db_bootstrap 阶段') => 'php bin/w setup:upgrade --stage=framework_db_bootstrap',
                '升级指定模块（位置参数）' => 'php bin/w setup:upgrade Weline_Demo',
                '升级指定模块（长选项）' => 'php bin/w setup:upgrade --module Weline_Demo',
                '升级指定模块（短选项）' => 'php bin/w setup:upgrade -m Weline_Demo',
                '升级指定模块的模型' => 'php bin/w setup:upgrade --model -m Weline_Demo',
                '热更新 WLS 服务器' => 'php bin/w setup:upgrade --hot',
                __('跳过反射编译（加快 s:up）') => 'php bin/w setup:upgrade --skip-reflection-compile',
                __('同步执行优化（等待完成）') => 'php bin/w setup:upgrade --sync',
            ],
            'php bin/w setup:upgrade [--model|--route|--stage=<code>|--hot|--sync] [-m|--module=<模块名>]'
        );
    }

    /**
     * @inheritDoc
     */
    public function aliases(): array
    {
        return ['s:up'];
    }

    /**
     * 检查系统是否已安装
     * @return bool
     */
    private function checkSystemInstalled(): bool
    {
        // 检查 install.lock 文件是否存在
        if (!file_exists(BP . 'setup/install.lock')) {
            return false;
        }
        
        // 检查 env.php 文件是否存在
        $env_file = APP_PATH . 'etc/env.php';
        if (!file_exists($env_file)) {
            return false;
        }
        
        // 检查 env.php 文件是否为空
        $env_content = file_get_contents($env_file);
        if (empty(trim($env_content))) {
            return false;
        }
        
        return true;
    }

    /**
     * 处理系统未安装的情况
     * @param array $args 命令参数，含 -y/--yes 时不提示直接安装
     */
    private function handleSystemNotInstalled(array $args = []): void
    {
        $skipConfirm = isset($args['y']) || isset($args['yes']);

        // 若 env.php 中已配置数据库且可连接，直接安装不提示
        if ($this->envHasWorkingDb()) {
            $this->printing->note(__('env.php 中数据库配置有效，直接执行安装...'));
            $this->executeInstallWithExistingDb();
            return;
        }

        // -y/--yes 时不做交互检测：有 db 配置则用现有配置直接安装，否则走开发环境快速安装
        if ($skipConfirm) {
            if ($this->envHasDbConfig()) {
                $this->printing->note(__('检测到系统尚未安装（-y 模式），按 env.php 中 db 配置直接安装...'));
                $this->executeInstallWithExistingDb();
            } else {
                $this->printing->note(__('检测到系统尚未安装（-y 模式），直接执行开发环境快速安装...'));
                $this->executeDevelopmentInstall();
            }
            return;
        }

        $this->printing->warning(__('检测到系统尚未安装！'), __('警告'));
        
        // 检查是否在交互式环境中
        $isInteractive = $this->isInteractive();
        
        if (!$isInteractive) {
            // 非交互式环境，自动使用默认开发环境快速安装
            $this->printing->note(__('检测到非交互式环境，自动使用默认开发环境快速安装模式...'));
            $this->printing->warning(__('此模式将使用默认的 SQLite 数据库，仅适合开发测试使用。'));
            $this->printing->warning(__('生产环境强烈建议使用命令行安装并配置 MySQL 数据库！'));
            $this->printing->note(__('开始使用开发环境快速安装...'));
            $this->executeDevelopmentInstall();
            return;
        }
        
        // 交互式环境，显示选项
        $this->printing->note(__('请选择安装方式：'));
        $this->printing->note(__('1. 使用命令行安装（推荐生产环境）'));
        $this->printing->note(__('2. 使用默认开发环境快速安装（仅建议开发者使用，生产环境慎用）'));
        $this->printing->setup(__('请输入选项 (1/2)：'));
        
        // 获取用户输入
        $system = ObjectManager::getInstance(\Weline\Framework\App\System::class);
        $input = trim($system->input() ?? '');
        
        if ($input === '1') {
            // 显示 system:install 命令的帮助信息
            $this->showSystemInstallHelp();
        } elseif ($input === '2' || empty($input)) {
            // 如果输入为空，默认选择选项2
            if (empty($input)) {
                $this->printing->note(__('未输入选项，使用默认开发环境快速安装模式...'));
            } else {
                $this->printing->warning(__('您选择了开发环境快速安装模式。'));
            }
            // 确认是否继续使用开发环境安装
            $this->printing->warning(__('此模式将使用默认的 SQLite 数据库，仅适合开发测试使用。'));
            $this->printing->warning(__('生产环境强烈建议使用命令行安装并配置 MySQL 数据库！'));
            $this->printing->setup(__('是否继续？(y/n)：'));
            
            $confirm = strtolower(trim($system->input() ?? ''));
            if ($confirm === 'y' || $confirm === 'yes' || empty($confirm)) {
                // 如果确认输入为空，默认继续
                if (empty($confirm)) {
                    $this->printing->note(__('未输入确认，默认继续安装...'));
                }
                $this->printing->note(__('开始使用开发环境快速安装...'));
                // 继续执行原来的安装逻辑
                $this->executeDevelopmentInstall();
            } else {
                $this->printing->note(__('安装已取消。'));
                exit(0);
            }
        } else {
            $this->printing->error(__('无效的选项！请重新运行命令。'));
            exit(1);
        }
    }
    
    /**
     * 检查是否在交互式环境中
     * @return bool
     */
    private function isInteractive(): bool
    {
        // 检查 STDIN 是否是终端（TTY）
        if (function_exists('stream_isatty') && defined('STDIN')) {
            return stream_isatty(STDIN);
        }
        
        // 备用检查：尝试读取 STDIN 是否可用
        if (defined('STDIN') && is_resource(STDIN)) {
            // 检查 STDIN 是否可读
            $read = [STDIN];
            $write = [];
            $except = [];
            $result = @stream_select($read, $write, $except, 0);
            return $result !== false;
        }
        
        // 如果无法确定，假设是非交互式环境
        return false;
    }

    /**
     * 显示 system:install 命令的帮助信息
     */
    private function showSystemInstallHelp(): void
    {
        $this->printing->note(__(''));
        $this->printing->note(__('═══════════════════════════════════════════════════════════════'));
        $this->printing->setup(__('正在显示 system:install 命令帮助信息...'));
        $this->printing->note(__('═══════════════════════════════════════════════════════════════'));
        $this->printing->note(__(''));
        
        // 直接调用 system:install 命令的帮助方法
        try {
            $installCommand = ObjectManager::getInstance(\Weline\Framework\System\Console\System\Install::class);
            if (method_exists($installCommand, 'help')) {
                // 直接输出帮助信息，颜色代码会被保留
                $helpContent = $installCommand->help();
                // 直接打印到标准输出，保留 ANSI 颜色代码
                fwrite(STDOUT, $helpContent);
                fwrite(STDOUT, "\n");
            } else {
                $this->printing->error(__('无法加载 system:install 命令帮助信息'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('加载帮助信息失败：') . $e->getMessage());
        }
        
        exit(0);
    }

    /**
     * 执行开发环境快速安装
     */
    private function executeDevelopmentInstall(): void
    {
        # 使用默认配置生成（不再写入 sample 配置）
        $sandbox_db = Env::get('sandbox_db') ?? [];
        if (empty($sandbox_db)) {
            $sandbox_db = [
                'master' => [
                    'type' => 'sqlite',
                    'path' => APP_PATH . 'etc/db.sqlite',
                    'prefix' => 'w_',
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ],
                'slaves' => [],
            ];
        }
        
        if (isset($sandbox_db['master'])) {
            $sandbox_db['master']['path'] = APP_PATH . 'etc/db.sqlite';
        }
        $sandbox_db['slaves'] = [];
        
        Env::set('db', $sandbox_db);
        
        // 检查并初始化 area_routes 配置
        $areaRoutes = Env::getAreaRoutes();
        if (empty($areaRoutes) || empty($areaRoutes['backend']['prefix'] ?? '')) {
            // 初始化 area_routes 配置
            $newAreaRoutes = [
                'backend' => [
                    'prefix' => $areaRoutes['backend']['prefix'] ?? Text::random_string(32),
                    'description' => '后台管理',
                ],
                'rest_frontend' => [
                    'prefix' => $areaRoutes['rest_frontend']['prefix'] ?? 'api',
                    'description' => '前端 REST API',
                ],
                'rest_backend' => [
                    'prefix' => $areaRoutes['rest_backend']['prefix'] ?? Text::random_string(32),
                    'description' => '后台 REST API',
                ],
            ];
            Env::set('router.area_routes', $newAreaRoutes);
        }
        
        // 执行模块升级流程（原 module:upgrade 的功能）
        $this->executeModuleUpgrade([], []);
        
        # 触发系统升级后事件
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Framework_Setup::upgrade_after');
        
        $backendPrefix = Env::getAreaRoutePrefix('backend');
        $restBackendPrefix = Env::getAreaRoutePrefix('rest_backend');
        
        $this->printing->success(__('系统识别到您初次安装！已为您初始化安装参数。'), __('安装'));
        $this->printing->success(__('您的后台入口地址密钥：%{1} ', $backendPrefix), __('安装'));
        $this->printing->success(__('您的 REST 后台入口地址密钥：%{1}', $restBackendPrefix), __('安装'));
        $this->printing->success(__('使用server:start命令指定的地址访问网站，默认使用http://127.0.0.1:9981，例如:'), __('安装'));
        $this->printing->note(__('访问后台：%{1}/admin/login', 'http://127.0.0.1:9981/' . $backendPrefix), __('安装'));
        $this->printing->note(__('访问后台 REST API：%{1}', 'http://127.0.0.1:9981/' . $restBackendPrefix), __('安装'));
        $this->printing->warning(__('默认使用 sqlite 作为开发数据库，若要修改数据库请编辑 %{1} 下的 env.php 中 db 键。', APP_ETC_PATH), __('安装'));
        $this->printing->setup(__('由于您属于第一次安装，您可以使用命令行：php bin/w setup:upgrade , 然后使用：php bin/w server:start 快速开启本地开发服务器。'), __('安装'));
        
        # 设置环境用户
        Env::set('user', Env::user());
        
        # 设置安装文件
        file_put_contents(BP . 'setup/install.lock', date('Y-m-d H:i:s'));
    }

    /**
     * 检查 env.php 中是否有 pgsql/mysql 的 db 配置（不检测连接，供 -y 时用）
     */
    private function envHasDbConfig(): bool
    {
        $envFile = defined('APP_ETC_PATH') ? (APP_ETC_PATH . 'env.php') : (BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php');
        if (!is_file($envFile)) {
            return false;
        }
        $config = @include $envFile;
        if (!is_array($config) || empty($config['db']['master'])) {
            return false;
        }
        $m = $config['db']['master'];
        $type = strtolower((string)($m['type'] ?? ''));
        $dbname = trim((string)($m['database'] ?? ''));
        $user = trim((string)($m['username'] ?? ''));
        return ($type === 'pgsql' || $type === 'mysql') && $dbname !== '' && $user !== '';
    }

    /**
     * 检查 env.php 中是否已配置数据库且能连接（能连上则直接安装不提示）
     */
    private function envHasWorkingDb(): bool
    {
        if (!$this->envHasDbConfig()) {
            return false;
        }
        $envFile = defined('APP_ETC_PATH') ? (APP_ETC_PATH . 'env.php') : (BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php');
        $config = @include $envFile;
        if (!is_array($config) || empty($config['db']['master'])) {
            return false;
        }
        $m = $config['db']['master'];
        $type = strtolower((string)($m['type'] ?? ''));
        $host = $m['hostname'] ?? $m['host'] ?? '127.0.0.1';
        $port = $m['hostport'] ?? $m['port'] ?? ('pgsql' === $type ? '5432' : '3306');
        $dbname = trim((string)($m['database'] ?? ''));
        $user = trim((string)($m['username'] ?? ''));
        $pass = $m['password'] ?? '';
        if ($dbname === '' || $user === '') {
            return false;
        }
        if ($type !== 'pgsql' && $type !== 'mysql') {
            return false;
        }
        try {
            if ($type === 'pgsql') {
                $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $dbname;
                new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            } else {
                $dsn = "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $dbname . ";charset=utf8mb4";
                new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 使用 env.php 中已有数据库配置直接完成安装（不覆盖 db、不提示）
     */
    private function executeInstallWithExistingDb(): void
    {
        // 检查并初始化 area_routes 配置
        $areaRoutes = Env::getAreaRoutes();
        if (empty($areaRoutes) || empty($areaRoutes['backend']['prefix'] ?? '')) {
            $newAreaRoutes = [
                'backend' => [
                    'prefix' => $areaRoutes['backend']['prefix'] ?? Text::random_string(32),
                    'description' => '后台管理',
                ],
                'rest_frontend' => [
                    'prefix' => $areaRoutes['rest_frontend']['prefix'] ?? 'api',
                    'description' => '前端 REST API',
                ],
                'rest_backend' => [
                    'prefix' => $areaRoutes['rest_backend']['prefix'] ?? Text::random_string(32),
                    'description' => '后台 REST API',
                ],
            ];
            Env::set('router.area_routes', $newAreaRoutes);
        }

        $this->executeModuleUpgrade([], []);

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Framework_Setup::upgrade_after');

        $backendPrefix = Env::getAreaRoutePrefix('backend');
        $restBackendPrefix = Env::getAreaRoutePrefix('rest_backend');

        $this->printing->success(__('系统已按 env.php 中数据库配置完成安装。'), __('安装'));
        $this->printing->success(__('您的后台入口地址密钥：%{1} ', $backendPrefix), __('安装'));
        $this->printing->success(__('您的 REST 后台入口地址密钥：%{1}', $restBackendPrefix), __('安装'));
        $this->printing->note(__('访问后台：%{1}/admin/login', 'http://127.0.0.1:9981/' . $backendPrefix), __('安装'));

        Env::set('user', Env::user());
        file_put_contents(BP . 'setup/install.lock', date('Y-m-d H:i:s'));
    }
}
