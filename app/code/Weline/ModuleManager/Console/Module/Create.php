<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Event\EventData;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\Register;

class Create extends CommandAbstract
{
    private System $system;
    private string $configFile = '';
    private array $moduleConfig = [];

    public function __construct(Printing $printer, System $system)
    {
        $this->printer = $printer;
        $this->system = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        // 检查是否指定了模块名
        // 优先级：--module/-m 参数 > 位置参数[0] > 位置参数[1]
        $moduleName = $args['module'] ?? $args['m'] ?? $args[1] ?? '';
        if ($moduleName) {
            $moduleName = trim($moduleName);
        }
        
        // 如果没有指定模块名，显示选择菜单
        if (!$moduleName) {
            $moduleName = $this->selectOrCreateModule();
            if (!$moduleName) {
                return; // 用户取消
            }
        }
        
        // 检查是否有 --check 或 -c 参数，直接进入完整性检测
        if (isset($args['check']) || isset($args['c'])) {
            $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
            $this->checkModuleIntegrity($moduleName, $modulePath);
            return;
        }
        
        if ($moduleName) {
            // 如果指定了模块名，检查是否已存在配置文件
            $this->configFile = $this->getConfigFilePath($moduleName);
            if (file_exists($this->configFile)) {
                $this->moduleConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
                $this->printer->note(__('检测到模块 %{1} 的配置文件，进入二次操作模式', [$moduleName]));
                $this->handleSecondaryOperation($moduleName);
                return;
            }
        }

        // 新建模块流程
        $this->createNewModule($moduleName);
    }

    /**
     * 创建新模块
     */
    private function createNewModule(?string $moduleName = null): void
    {
        $this->printer->setup(__('=== 模块创建向导 ==='));
        $this->printer->printing("\n");

        // 1. 询问模块名
        if (!$moduleName) {
            $this->printer->note(__('请输入模块名称（格式：Vendor_ModuleName，例如：Weline_Demo）'));
            $moduleName = trim($this->system->input());
            if (empty($moduleName)) {
                $this->printer->error(__('模块名称不能为空'));
                return;
            }
        } else {
            // 如果从命令行参数获取，也需要trim
            $moduleName = trim($moduleName);
        }

        // 验证模块名格式
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $moduleName)) {
            $this->printer->error(__('模块名称格式不正确，应为 Vendor_ModuleName 格式'));
            return;
        }

        // 检查是否有之前的操作记录（配置文件）
        $this->configFile = $this->getConfigFilePath($moduleName);
        if (file_exists($this->configFile)) {
            // 恢复现场操作数据
            $this->moduleConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
            
            $this->printer->printing("\n");
            $this->printer->note(__('=== 检测到模块 %{1} 之前的操作记录 ===', [$moduleName]));
            // 在 heredoc 外部计算值，避免语法错误
            $createdAt = !empty($this->moduleConfig['created_at']) ? $this->moduleConfig['created_at'] : '未知';
            $updatedAt = !empty($this->moduleConfig['updated_at']) ? $this->moduleConfig['updated_at'] : '未知';
            $this->printer->printing("创建时间：{$createdAt}\n");
            $this->printer->printing("最后更新：{$updatedAt}\n");
            
            // 显示已配置的内容摘要
            $this->showModuleConfigSummary();
            
            // 检查模块是否已生成
            $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
            $moduleGenerated = is_dir($modulePath) && file_exists($modulePath . DS . 'register.php');
            
            if ($moduleGenerated) {
                $this->printer->note(__('模块已生成'));
            } else {
                $this->printer->note(__('模块尚未生成'));
            }
            
            $this->printer->printing("\n");
            $this->printer->note(__('请选择操作：'));
            $this->printer->note(__('1. 继续创建流程（从上次中断的地方继续）'));
            $this->printer->note(__('2. 进入二次操作模式（添加控制器、继承模块等）'));
            if (!$moduleGenerated) {
                $this->printer->note(__('3. 直接生成模块（使用当前配置）'));
            }
            $this->printer->note(__('4. 重新开始（将覆盖之前的配置）'));
            $this->printer->note(__('0. 退出'));
            
            $choice = trim($this->system->input());
            
            switch ($choice) {
                case '1':
                    // 继续创建流程
                    $this->continueModuleCreation($moduleName);
                    return;
                case '2':
                    // 进入二次操作模式
                    $this->handleSecondaryOperation($moduleName);
                    return;
                case '3':
                    if (!$moduleGenerated) {
                        // 直接生成模块
                        $controllers = $this->moduleConfig['controllers'] ?? [];
                        $this->generateModule($moduleName, $controllers);
                        $this->saveConfig();
                        return;
                    }
                    // 如果模块已生成，fall through 到 default
                case '4':
                    // 重新开始
                    $this->printer->note(__('确认要重新开始吗？这将覆盖所有之前的配置！(y/n，默认：n)'));
                    $confirm = trim(strtolower($this->system->input()));
                    if ($confirm === 'y' || $confirm === 'yes') {
                        // 清空配置，重新开始
                        $this->moduleConfig = [
                            'module_name' => $moduleName,
                            'version' => '1.0.0',
                            'description' => '',
                            'router' => '',
                            'controllers' => [],
                            'events' => [],
                            'plugins' => [],
                            'models' => [],
                            'services' => [],
                            'extends' => [],
                            'env_configured' => false,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ];
                        $this->saveConfig();
                        $this->printer->success(__('已重置配置，开始新的创建流程'));
                        // 继续执行后续流程
                    } else {
                        $this->printer->note(__('已取消操作'));
                        return;
                    }
                    break;
                case '0':
                    $this->printer->note(__('已退出'));
                    return;
                default:
                    $this->printer->warning(__('无效选项，默认继续创建流程'));
                    $this->continueModuleCreation($moduleName);
                    return;
            }
        }

        // 检查模块是否已存在
        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        if (is_dir($modulePath)) {
            $this->printer->note(__('模块 %{1} 已存在，直接进入二次操作模式', [$moduleName]));
                // 如果配置文件不存在，创建空配置
                if (!isset($this->moduleConfig) || empty($this->moduleConfig)) {
                    $this->configFile = $this->getConfigFilePath($moduleName);
                    $this->moduleConfig = [
                    'module_name' => $moduleName,
                    'version' => '1.0.0',
                    'description' => '',
                    'router' => '',
                    'controllers' => [],
                    'events' => [],
                    'plugins' => [],
                    'models' => [],
                    'services' => [],
                    'extends' => [],
                    'env_configured' => false,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
            }
            $this->handleSecondaryOperation($moduleName);
            return;
        }

        // 初始化配置（如果不存在）
        if (!isset($this->moduleConfig) || empty($this->moduleConfig)) {
            $this->configFile = $this->getConfigFilePath($moduleName);
            $this->moduleConfig = [
                'module_name' => $moduleName,
                'version' => '1.0.0',
                'description' => '',
                'router' => '',
                'controllers' => [],
                'events' => [],
                'plugins' => [],
                'models' => [],
                'services' => [],
                'extends' => [],
                'env_configured' => false,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            // 立即保存初始配置
            $this->saveConfig();
        }

        // 继续创建流程
        $this->continueModuleCreation($moduleName);
    }

    /**
     * 继续模块创建流程（智能跳过已完成的步骤）
     */
    private function continueModuleCreation(string $moduleName): void
    {
        $this->printer->printing("\n");
        $this->printer->setup(__('=== 继续模块创建流程 ==='));
        $this->printer->printing("\n");

        // 2. 询问版本号（如果未设置或为默认值）
        if (empty($this->moduleConfig['version']) || $this->moduleConfig['version'] === '1.0.0') {
            $this->printer->note(__('步骤 1/6：请输入版本号（默认：1.0.0）'));
            $version = trim($this->system->input());
            $this->moduleConfig['version'] = $version ?: '1.0.0';
            $this->saveConfig(); // 立即保存
            $this->printer->success(__('版本号已设置：%{1}', [$this->moduleConfig['version']]));
        } else {
            $this->printer->note(__('步骤 1/6：版本号（已设置：%{1}，跳过）', [$this->moduleConfig['version']]));
        }

        // 3. 询问描述（如果未设置）
        if (empty($this->moduleConfig['description'])) {
            $this->printer->note(__('步骤 2/6：请输入模块描述（可选，直接回车跳过）'));
            $description = trim($this->system->input());
            $this->moduleConfig['description'] = $description;
            $this->saveConfig(); // 立即保存
            if ($description) {
                $this->printer->success(__('描述已设置：%{1}', [$description]));
            }
        } else {
            $this->printer->note(__('步骤 2/6：模块描述（已设置：%{1}，跳过）', [$this->moduleConfig['description'] ?: '无']));
        }

        // 4. 询问路由前缀（如果未设置）
        if (empty($this->moduleConfig['router'])) {
            $this->printer->note(__('步骤 3/6：请输入路由前缀（默认：模块名转小写，留空则不创建env.php）'));
            $router = trim($this->system->input());
            $router = $router ?: strtolower($moduleName);
            $this->moduleConfig['router'] = $router;
            
            // 如果输入了路由前缀（非空），则自动配置env.php
            $this->moduleConfig['env_configured'] = !empty($router);
            $this->saveConfig(); // 立即保存
            if ($router) {
                $this->printer->success(__('路由前缀已设置：%{1}', [$router]));
            }
        } else {
            $this->printer->note(__('步骤 3/6：路由前缀（已设置：%{1}，跳过）', [$this->moduleConfig['router']]));
        }

        // 5. 询问是否创建控制器（如果未创建）
        $controllers = $this->moduleConfig['controllers'] ?? [];
        if (empty($controllers)) {
            $this->printer->note(__('步骤 4/6：是否要创建控制器？(y/n，默认：y，直接回车将创建默认 helloword 控制器)'));
            $createControllerInput = trim(strtolower($this->system->input()));
            
            // 判断用户输入
            if ($createControllerInput === 'n' || $createControllerInput === 'no') {
                // 用户明确选择不创建控制器
                $this->printer->note(__('已跳过控制器创建'));
            } elseif ($createControllerInput === 'y' || $createControllerInput === 'yes') {
                // 用户明确输入 'y'，进入自定义路由输入流程
                $newControllers = $this->askForControllers($moduleName);
                $controllers = array_merge($controllers, $newControllers);
                $this->moduleConfig['controllers'] = $controllers;
                $this->saveConfig(); // 立即保存
                $this->printer->success(__('已添加 %{1} 个控制器', [count($newControllers)]));
            } else {
                // 默认情况（直接回车），直接创建默认 helloword 控制器，不再询问路由
                $controllers[] = [
                    'name' => 'Helloword',
                    'routes' => ['helloword'],
                    'type' => 'Frontend',
                    'is_default' => true
                ];
                $this->moduleConfig['controllers'] = $controllers;
                $this->saveConfig(); // 立即保存
                $this->printer->success(__('已添加默认 helloword 控制器'));
            }
        } else {
            $this->printer->note(__('步骤 4/6：控制器（已配置 %{1} 个，跳过）', [count($controllers)]));
        }

        // 6. 询问是否进行高级定制
        $hasAdvancedConfig = !empty($this->moduleConfig['events']) || 
                            !empty($this->moduleConfig['plugins']) || 
                            !empty($this->moduleConfig['models']) || 
                            !empty($this->moduleConfig['services']);
        
        if (!$hasAdvancedConfig) {
            $this->printer->note(__('步骤 5/6：是否进行高级定制？(y/n，默认：n)'));
            $advanced = trim(strtolower($this->system->input()));
            $advanced = ($advanced === 'y' || $advanced === 'yes');

            if ($advanced) {
                $this->handleAdvancedCustomization($moduleName);
            } else {
                $this->printer->note(__('已跳过高级定制'));
            }
        } else {
            $this->printer->note(__('步骤 5/6：高级定制（已配置，跳过）'));
        }

        // 7. 询问是否生成模块
        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        $moduleGenerated = is_dir($modulePath) && file_exists($modulePath . DS . 'register.php');
        
        if (!$moduleGenerated) {
            $this->printer->note(__('步骤 6/6：是否现在生成模块？(y/n，默认：y)'));
            $generate = trim(strtolower($this->system->input()));
            $generate = ($generate === '' || $generate === 'y' || $generate === 'yes');

            if ($generate) {
                // 生成模块
                $this->generateModule($moduleName, $controllers);
                $this->printer->success(__('模块生成完成！'));
            } else {
                $this->printer->note(__('已跳过模块生成，配置已保存，您可以稍后通过二次操作模式生成模块'));
            }
        } else {
            $this->printer->note(__('步骤 6/6：模块已生成，跳过'));
        }

        // 最后保存一次配置（确保所有数据都已保存）
        $this->saveConfig();
        
        $this->printer->printing("\n");
        $this->printer->note(__('创建流程完成！'));
        $this->printer->note(__('如需继续操作，请运行：php bin/w module:create -m %{1}', [$moduleName]));
    }

    /**
     * 询问控制器信息
     */
    private function askForControllers(string $moduleName): array
    {
        $controllers = [];
        $controllerIndex = 1;

        while (true) {
            $this->printer->note(__('=== 控制器 #%{1} ===', [$controllerIndex]));
            
            // 先选择控制器类型
            $this->printer->note(__('请选择控制器类型：'));
            $this->printer->note(__('1. Frontend（前端控制器，创建在 Controller/Frontend 目录，继承 FrontendController）'));
            $this->printer->note(__('2. Backend（后端控制器，创建在 Controller/Backend 目录，继承 BackendController）【必须配置Acl】'));
            $this->printer->note(__('3. FrontendApi（前端API控制器，创建在 Api/Rest/V1/Frontend 目录，继承 FrontendRestController）【公开接口无需Acl，需要登录的接口也无需Acl】'));
            $this->printer->note(__('4. BackendApi（后端API控制器，创建在 Api/Rest/V1/Backend 目录，继承 BackendRestController）【必须配置Acl】'));
            $this->printer->note(__('0. 跳过创建控制器'));
            
            $typeChoice = trim($this->system->input());
            
            $controllerType = '';
            switch ($typeChoice) {
                case '1':
                    $controllerType = 'Frontend';
                    break;
                case '2':
                    $controllerType = 'Backend';
                    break;
                case '3':
                    $controllerType = 'FrontendApi';
                    break;
                case '4':
                    $controllerType = 'BackendApi';
                    break;
                case '0':
                    $this->printer->note(__('已跳过控制器创建'));
                    return $controllers;
                default:
                    $this->printer->warning(__('无效选择，默认创建 Frontend 控制器'));
                    $controllerType = 'Frontend';
            }
            
            // 显示Acl配置提示
            $this->printer->printing("\n");
            if ($controllerType === 'Backend') {
                $this->printer->note(__('⚠️  重要提示：后端控制器必须配置Acl权限注解！'));
                $this->printer->note(__('   示例：'));
                $this->printer->printing("   #[\Weline\Framework\Acl\Acl('模块名::控制器名', '菜单名称', '图标', '描述')]\n");
                $this->printer->printing("   class 控制器名 extends BackendController\n");
                $this->printer->printing("   {\n");
                $this->printer->printing("       #[\Weline\Framework\Acl\Acl('模块名::控制器名::方法名', '操作名称', '', '操作描述')]\n");
                $this->printer->printing("       public function index() { ... }\n");
                $this->printer->printing("   }\n");
            } elseif ($controllerType === 'BackendApi') {
                $this->printer->note(__('⚠️  重要提示：后端API控制器必须配置Acl权限注解！'));
                $this->printer->note(__('   示例：'));
                $this->printer->printing("   #[\Weline\Framework\Acl\Acl('模块名::api::控制器名', 'API分组', '图标', '描述')]\n");
                $this->printer->printing("   class 控制器名 extends BackendRestController\n");
                $this->printer->printing("   {\n");
                $this->printer->printing("       #[\Weline\Framework\Acl\Acl('模块名::api::控制器名::方法名', '接口名称', '', '接口描述', '模块名::api::控制器名')]\n");
                $this->printer->printing("       public function index() { ... }\n");
                $this->printer->printing("   }\n");
            } elseif ($controllerType === 'FrontendApi') {
                $this->printer->note(__('ℹ️  提示：前端API控制器有两种模式：'));
                $this->printer->note(__('   1. 公开接口：不需要Acl注解，不需要登录即可访问'));
                $this->printer->note(__('   2. 需要登录：不需要Acl注解，但需要用户登录后才能访问（由FrontendRestController自动处理）'));
            }
            $this->printer->printing("\n");
            
            // 询问控制器名称
            $this->printer->note(__('请输入控制器名称（例如：Index、Product、User，直接回车将使用默认名称 Helloword）'));
            $controllerName = trim($this->system->input());
            
            if (empty($controllerName)) {
                $controllerName = 'Helloword';
            } else {
                // 转换为驼峰命名
                $controllerName = str_replace(['-', '_'], ' ', $controllerName);
                $controllerName = str_replace(' ', '', ucwords($controllerName));
                $controllerName = ucfirst($controllerName);
            }
            
            // 询问路由（可选）
            $this->printer->note(__('请输入路由（多个路由用逗号分隔，留空则使用控制器名称作为路由）'));
            $routesInput = trim($this->system->input());
            
            if (empty($routesInput)) {
                // 使用控制器名称作为默认路由
                $routes = [strtolower($controllerName)];
            } else {
            $routes = array_map('trim', explode(',', $routesInput));
            $routes = array_filter($routes); // 移除空值
            if (empty($routes)) {
                $routes = [strtolower($controllerName)];
            }
        }
        
        // 如果是后端控制器，询问Acl信息
        $aclInfo = null;
        if ($controllerType === 'Backend' || $controllerType === 'BackendApi') {
            $this->printer->printing("\n");
            $this->printer->note(__('=== 配置Acl权限信息 ==='));
            $this->printer->note(__('提示：前缀为 %{1}::，您只需要填写后面的部分', [$moduleName]));
            
            // 类级别Acl
            if ($controllerType === 'BackendApi') {
                $defaultClassAcl = 'api::' . $controllerName;
                $fullExample = $moduleName . '::api:' . strtolower($controllerName);
                $this->printer->note(__('请输入类级别Acl的source_id（例如完整格式：%{1}，您只需输入：api::%{2}，前缀 %{3}:: 已自动添加，直接回车使用默认值）', [$fullExample, $controllerName, $moduleName]));
            } else {
                $defaultClassAcl = $controllerName;
                $fullExample = $moduleName . '::' . strtolower($controllerName);
                $this->printer->note(__('请输入类级别Acl的source_id（例如完整格式：%{1}，您只需输入：%{2}，前缀 %{3}:: 已自动添加，直接回车使用默认值）', [$fullExample, $controllerName, $moduleName]));
            }
            $classAclSourceId = trim($this->system->input());
            
            if (empty($classAclSourceId)) {
                $classAclSourceId = $defaultClassAcl;
            }
            
            // 构建完整的source_id
            $fullClassAclSourceId = $moduleName . '::' . $classAclSourceId;
            
            // 类级别Acl的source_name
            $this->printer->note(__('请输入类级别Acl的名称（必填，例如：%{1}管理）', [$controllerName]));
            $classAclName = trim($this->system->input());
            
            if (empty($classAclName)) {
                $this->printer->warning(__('Acl名称不能为空，使用默认值：%{1}', [$controllerName]));
                $classAclName = $controllerName;
            }
            
            // 类级别Acl的icon（可选）
            $this->printer->note(__('请输入类级别Acl的图标（可选，例如：mdi mdi-home，留空则不设置）'));
            $classAclIcon = trim($this->system->input());
            
            // 类级别Acl的文档（可选）
            $this->printer->note(__('请输入类级别Acl的文档描述（可选，留空则不设置）'));
            $classAclDocument = trim($this->system->input());
            
            // 方法级别Acl
            $fullMethodExample = $fullClassAclSourceId . '::index';
            $this->printer->note(__('请输入方法级别Acl的source_id（例如完整格式：%{1}，您只需输入：index，前缀 %{2}:: 已自动添加，直接回车使用默认值 index）', [$fullMethodExample, $fullClassAclSourceId]));
            $methodAclSourceId = trim($this->system->input());
            
            if (empty($methodAclSourceId)) {
                $methodAclSourceId = 'index';
            }
            
            // 构建完整的方法级别source_id
            $fullMethodAclSourceId = $fullClassAclSourceId . '::' . $methodAclSourceId;
            
            // 方法级别Acl的source_name
            $this->printer->note(__('请输入方法级别Acl的名称（必填，例如：%{1}列表）', [$controllerName]));
            $methodAclName = trim($this->system->input());
            
            if (empty($methodAclName)) {
                $this->printer->warning(__('Acl名称不能为空，使用默认值：%{1}', [$methodAclSourceId]));
                $methodAclName = $methodAclSourceId;
            }
            
            // 方法级别Acl的icon（可选）
            $this->printer->note(__('请输入方法级别Acl的图标（可选，例如：mdi mdi-list，留空则不设置）'));
            $methodAclIcon = trim($this->system->input());
            
            // 方法级别Acl的文档（可选）
            $this->printer->note(__('请输入方法级别Acl的文档描述（可选，留空则不设置）'));
            $methodAclDocument = trim($this->system->input());
            
            $aclInfo = [
                'class' => [
                    'source_id' => $fullClassAclSourceId,
                    'source_name' => $classAclName,
                    'icon' => $classAclIcon,
                    'document' => $classAclDocument,
                ],
                'method' => [
                    'source_id' => $fullMethodAclSourceId,
                    'source_name' => $methodAclName,
                    'icon' => $methodAclIcon,
                    'document' => $methodAclDocument,
                ],
            ];
        }
        
        $controllers[] = [
            'name' => $controllerName,
            'routes' => $routes,
            'type' => $controllerType,
            'is_default' => false,
            'acl' => $aclInfo,
        ];

            $this->printer->success(__('已添加控制器：%{1} (%{2})，路由：%{3}', [
                $controllerName,
                $controllerType,
                implode(', ', $routes)
            ]));

            // 每添加一个控制器后立即保存配置
            if (!empty($controllers)) {
                $this->moduleConfig['controllers'] = array_merge($this->moduleConfig['controllers'] ?? [], $controllers);
                $this->saveConfig();
            }

            // 询问是否继续添加控制器
            $this->printer->note(__('是否继续添加控制器？(y/n，默认：n)'));
            $continue = trim(strtolower($this->system->input()));
            if ($continue !== 'y' && $continue !== 'yes') {
                break;
            }

            $controllerIndex++;
        }

        return $controllers;
    }

    /**
     * 解析路由
     */
    private function parseRoute(string $route): array
    {
        $route = trim($route, '/');
        $parts = explode('/', $route);
        $parts = array_filter($parts); // 移除空值
        $parts = array_values($parts); // 重新索引

        $type = 'Frontend';
        $name = 'Index';

        // 判断控制器类型
        $lowerParts = array_map('strtolower', $parts);
        
        // 判断控制器类型：区分前端API和后端API
        if (in_array('backend', $lowerParts)) {
            // 检查是否是后端API
            if (in_array('api', $lowerParts) || in_array('rest', $lowerParts)) {
                $type = 'BackendApi';
            } else {
                $type = 'Backend';
            }
        } elseif (in_array('api', $lowerParts) || in_array('rest', $lowerParts)) {
            $type = 'FrontendApi';
        } elseif (in_array('frontend', $lowerParts)) {
            $type = 'Frontend';
        }

        // 提取控制器名（取最后一个非保留关键字的部分）
        $reservedKeywords = ['api', 'rest', 'v1', 'backend', 'frontend'];
        $nameParts = array_filter($parts, function($part) use ($reservedKeywords) {
            return !in_array(strtolower($part), $reservedKeywords);
        });

        if (!empty($nameParts)) {
            $lastPart = end($nameParts);
            // 处理连字符和下划线，转换为驼峰命名
            $name = str_replace(['-', '_'], ' ', $lastPart);
            $name = str_replace(' ', '', ucwords($name));
            // 确保首字母大写
            $name = ucfirst($name);
            if (empty($name)) {
                $name = 'Index';
            }
        }

        return [
            'type' => $type,
            'name' => $name,
            'route' => $route
        ];
    }

    /**
     * 处理高级定制
     */
    private function handleAdvancedCustomization(string $moduleName): void
    {
        $this->printer->note(__('=== 高级定制选项 ==='));

        while (true) {
            $this->printer->printing("\n");
            $this->printer->note(__('请选择高级定制选项：'));
            $this->printer->note(__('1. 创建事件（Observer）'));
            $this->printer->note(__('2. 创建插件（Plugin）'));
            $this->printer->note(__('3. 创建模型（Model）'));
            $this->printer->note(__('4. 创建服务（Service）'));
            $this->printer->note(__('5. 提取并翻译i18n词典'));
            $this->printer->note(__('0. 完成高级定制'));

            $choice = trim($this->system->input());

            switch ($choice) {
                case '1':
                    $this->createEvent($moduleName);
                    break;
                case '2':
                    $this->createPlugin($moduleName);
                    break;
                case '3':
                    $this->createModel($moduleName);
                    break;
                case '4':
                    $this->createService($moduleName);
                    break;
                case '5':
                    $this->extractAndTranslateI18n($moduleName);
                    break;
                case '0':
                    return;
                default:
                    $this->printer->warning(__('无效选项，请重新选择'));
            }
        }
    }

    /**
     * 创建事件（会立即保存配置）
     */
    private function createEvent(string $moduleName): void
    {
        // 使用 EventRegistry 获取事件信息
        /** @var EventRegistry $eventRegistry */
        $eventRegistry = ObjectManager::getInstance(EventRegistry::class);
        $registry = $eventRegistry->getRegistry();
        $availableEvents = [];
        $eventsInfo = [];
        
        if (isset($registry['events']) && is_array($registry['events'])) {
            $availableEvents = array_keys($registry['events']);
            $eventsInfo = $registry['events'];
        }

        if (empty($availableEvents)) {
            $this->printer->note(__('未找到可用事件（generated/events.php 不存在或为空）'));
            $this->printer->note(__('是否创建自定义事件？(y/n，默认：y)'));
            $createCustom = trim(strtolower($this->system->input()));
            $createCustom = ($createCustom === '' || $createCustom === 'y' || $createCustom === 'yes');
            
            if (!$createCustom) {
                return;
            }
            
            // 直接输入自定义事件名称
            $this->printer->note(__('请输入事件名称（例如：model_save_before）'));
            $eventName = trim($this->system->input());
            
            if (empty($eventName)) {
                $this->printer->warning(__('事件名称不能为空'));
                return;
            }

            $this->moduleConfig['events'][] = [
                'name' => $eventName,
                'is_custom' => true,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->printer->success(__('自定义事件 %{1} 已添加到配置', [$eventName]));
            return;
        }

        // 有可用事件，提供搜索选择功能
        $this->printer->note(__('找到 %{1} 个可用事件', [count($availableEvents)]));
        $this->printer->note(__('请输入搜索关键词搜索事件，或直接输入事件名称'));
        $this->printer->note(__('留空则显示所有事件（最多显示50个）'));
        
        $searchKeyword = trim($this->system->input());
        
        $filteredEvents = $availableEvents;
        if (!empty($searchKeyword)) {
            // 搜索匹配的事件
            $filteredEvents = array_filter($availableEvents, function($event) use ($searchKeyword) {
                return stripos($event, $searchKeyword) !== false;
            });
            $filteredEvents = array_values($filteredEvents); // 重新索引
            
            if (empty($filteredEvents)) {
                $this->printer->warning(__('未找到匹配的事件：%{1}', [$searchKeyword]));
                $this->printer->note(__('是否创建自定义事件 %{1}？(y/n，默认：y)', [$searchKeyword]));
                $createCustom = trim(strtolower($this->system->input()));
                $createCustom = ($createCustom === '' || $createCustom === 'y' || $createCustom === 'yes');
                
                if ($createCustom) {
                    $this->moduleConfig['events'][] = [
                        'name' => $searchKeyword,
                        'is_custom' => true,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $this->printer->success(__('自定义事件 %{1} 已添加到配置', [$searchKeyword]));
                }
                return;
            }
            
            $this->printer->note(__('找到 %{1} 个匹配的事件：', [count($filteredEvents)]));
        } else {
            $this->printer->note(__('显示前 %{1} 个事件：', [min(50, count($filteredEvents))]));
        }

        // 显示搜索结果（最多50个）
        $displayEvents = array_slice($filteredEvents, 0, 50);
        $index = 1;
        foreach ($displayEvents as $eventName) {
            $eventInfo = $eventsInfo[$eventName] ?? null;
            $displayName = $eventInfo['name'] ?? $eventName;
            $hasDoc = $eventInfo['has_doc'] ?? false;
            
            // 显示：序号. 事件名 (规约名称) [文档]
            $line = "  {$index}. {$eventName}";
            if ($displayName !== $eventName) {
                $line .= " ({$displayName})";
            }
            if ($hasDoc) {
                $line .= " [有文档]";
            }
            $this->printer->printing($line . "\n");
            $index++;
        }
        
        if (count($filteredEvents) > 50) {
            $this->printer->note(__('... 还有 %{1} 个事件未显示，请使用更精确的搜索关键词', [count($filteredEvents) - 50]));
        }

        // 让用户选择
        if (count($displayEvents) === 1) {
            // 只有一个结果，显示详细信息
            $eventName = $displayEvents[0];
            $this->showEventDetail($eventName, $eventsInfo[$eventName] ?? null);
            $this->printer->note(__('自动选择唯一匹配的事件：%{1}', [$eventName]));
        } else {
            $this->printer->note(__('请选择事件（输入序号查看详情，输入序号+回车确认选择，或直接输入事件名称，留空则创建自定义事件）'));
            $this->printer->note(__('提示：输入序号后按回车可查看该事件的详细信息'));
            $choice = trim($this->system->input());
            
            if (empty($choice)) {
                // 留空，询问是否创建自定义事件
                $this->printer->note(__('是否创建自定义事件？(y/n，默认：n)'));
                $createCustom = trim(strtolower($this->system->input()));
                if ($createCustom !== 'y' && $createCustom !== 'yes') {
                    return;
                }
                
                $this->printer->note(__('请输入自定义事件名称'));
                $eventName = trim($this->system->input());
                if (empty($eventName)) {
                    $this->printer->warning(__('事件名称不能为空'));
                    return;
                }
                
                $this->moduleConfig['events'][] = [
                    'name' => $eventName,
                    'is_custom' => true,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $this->printer->success(__('自定义事件 %{1} 已添加到配置', [$eventName]));
                return;
            } elseif (is_numeric($choice)) {
                // 输入了序号，显示详细信息并确认
                $choiceIndex = (int)$choice;
                if ($choiceIndex < 1 || $choiceIndex > count($displayEvents)) {
                    $this->printer->error(__('无效的序号'));
                    return;
                }
                $eventName = $displayEvents[$choiceIndex - 1];
                $eventInfo = $eventsInfo[$eventName] ?? null;
                
                // 显示详细信息
                $this->showEventDetail($eventName, $eventInfo);
                
                // 确认选择
                $this->printer->note(__('是否选择此事件？(y/n，默认：y)'));
                $confirm = trim(strtolower($this->system->input()));
                if ($confirm !== '' && $confirm !== 'y' && $confirm !== 'yes') {
                    return;
                }
            } else {
                // 直接输入了事件名称
                $eventName = $choice;
                $eventInfo = $eventsInfo[$eventName] ?? null;
                
                // 如果事件存在，显示详细信息
                if ($eventInfo) {
                    $this->showEventDetail($eventName, $eventInfo);
                }
            }
        }

        // 检查是否是现有事件
        $isExistingEvent = in_array($eventName, $availableEvents);
        
        $this->moduleConfig['events'][] = [
            'name' => $eventName,
            'is_custom' => !$isExistingEvent,
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($isExistingEvent) {
            $this->printer->success(__('事件 %{1} 已添加到配置（使用现有事件）', [$eventName]));
        } else {
            $this->printer->success(__('自定义事件 %{1} 已添加到配置', [$eventName]));
        }
        
        // 立即保存配置
        $this->saveConfig();
    }

    /**
     * 显示事件详细信息
     */
    private function showEventDetail(string $eventName, ?array $eventInfo): void
    {
        $this->printer->printing("\n");
        $this->printer->note(__('=== 事件详细信息 ==='));
        $this->printer->printing("事件名: {$eventName}\n");
        
        if ($eventInfo) {
            $displayName = $eventInfo['name'] ?? $eventName;
            if ($displayName !== $eventName) {
                $this->printer->printing("规约名称: {$displayName}\n");
            }
            
            $module = $eventInfo['module'] ?? '';
            if ($module) {
                $this->printer->printing("所属模块: {$module}\n");
            }
            
            $description = $eventInfo['description'] ?? '';
            if ($description) {
                $this->printer->printing("描述: {$description}\n");
            }
            
            $hasDoc = $eventInfo['has_doc'] ?? false;
            $docPath = $eventInfo['doc_path'] ?? '';
            if ($hasDoc && $docPath) {
                // 构建前端文档URL
                // 假设开发文档的前端地址格式为：/dev/tool/document?path=模块/doc/event/文档名
                $moduleName = $eventInfo['module'] ?? '';
                if ($moduleName) {
                    // 将模块名转换为路径格式：Weline_Event -> Weline/Event
                    $modulePath = str_replace('_', '/', $moduleName);
                    // doc_path 格式通常是：doc/event/文档名.md
                    $frontendDocUrl = "/dev/tool/document?path={$modulePath}/{$docPath}";
                    $this->printer->printing("文档路径: {$docPath}\n");
                    $this->printer->printing("前端文档地址: {$frontendDocUrl}\n");
                    $this->printer->note(__('提示：可以在浏览器中访问上述地址查看完整文档'));
                } else {
                    $this->printer->printing("文档路径: {$docPath}\n");
                }
            } elseif ($hasDoc) {
                $this->printer->printing("状态: 有文档（路径未配置）\n");
            }
        } else {
            $this->printer->printing("状态: 未找到详细信息\n");
        }
        
        $this->printer->printing("\n");
    }

    /**
     * 创建插件（会立即保存配置）
     */
    private function createPlugin(string $moduleName): void
    {
        $this->printer->note(__('请输入插件目标类（例如：Weline\\Framework\\Database\\Model）'));
        $targetClass = trim($this->system->input());
        
        if (empty($targetClass)) {
            $this->printer->warning(__('目标类不能为空'));
            return;
        }

        // 检查类是否存在
        if (!class_exists($targetClass) && !interface_exists($targetClass)) {
            $this->printer->warning(__('类 %{1} 不存在', [$targetClass]));
            $this->printer->note(__('是否继续？(y/n，默认：n)'));
            $continue = trim(strtolower($this->system->input()));
            if ($continue !== 'y' && $continue !== 'yes') {
                return;
            }
        } else {
            $this->printer->success(__('类 %{1} 存在', [$targetClass]));
        }

        $this->printer->note(__('请输入插件方法名（例如：save）'));
        $method = trim($this->system->input());
        
        if (empty($method)) {
            $this->printer->warning(__('方法名不能为空'));
            return;
        }

        // 检查方法是否存在
        if (class_exists($targetClass) || interface_exists($targetClass)) {
            $reflection = new \ReflectionClass($targetClass);
            if (!$reflection->hasMethod($method)) {
                $this->printer->warning(__('方法 %{1}::%{2}() 不存在', [$targetClass, $method]));
                $this->printer->note(__('是否继续？(y/n，默认：n)'));
                $continue = trim(strtolower($this->system->input()));
                if ($continue !== 'y' && $continue !== 'yes') {
                    return;
                }
            } else {
                $this->printer->success(__('方法 %{1}::%{2}() 存在', [$targetClass, $method]));
            }
        }

        $this->moduleConfig['plugins'][] = [
            'target_class' => $targetClass,
            'method' => $method,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->printer->success(__('插件已添加到配置'));
        
        // 立即保存配置
        $this->saveConfig();
    }

    /**
     * 创建模型（会立即保存配置）
     */
    private function createModel(string $moduleName): void
    {
        $this->printer->note(__('请输入模型名称（例如：Product）'));
        $modelName = trim($this->system->input());
        
        if (empty($modelName)) {
            $this->printer->warning(__('模型名称不能为空'));
            return;
        }

        // 询问是否配置字段
        $this->printer->note(__('是否配置模型字段？(y/n，默认：n)'));
        $configureFields = trim(strtolower($this->system->input()));
        $configureFields = ($configureFields === 'y' || $configureFields === 'yes');

        $fields = [];
        if ($configureFields) {
            $fieldIndex = 1;
            while (true) {
                $this->printer->note(__('=== 字段 #%{1} ===', [$fieldIndex]));
                
                $this->printer->note(__('请输入字段名（例如：product_id）'));
                $fieldName = trim($this->system->input());
                if (empty($fieldName)) {
                    break;
                }

                $this->printer->note(__('请输入字段类型（例如：varchar, int, text, datetime）'));
                $fieldType = trim($this->system->input());
                if (empty($fieldType)) {
                    $fieldType = 'varchar';
                }

                $this->printer->note(__('请输入字段长度（可选，例如：255，int类型可留空）'));
                $fieldLength = trim($this->system->input());

                $this->printer->note(__('请输入字段注释（可选）'));
                $fieldComment = trim($this->system->input());

                $this->printer->note(__('是否为主键？(y/n，默认：n)'));
                $isPrimary = trim(strtolower($this->system->input()));
                $isPrimary = ($isPrimary === 'y' || $isPrimary === 'yes');

                $this->printer->note(__('是否允许为空？(y/n，默认：y)'));
                $isNullable = trim(strtolower($this->system->input()));
                $isNullable = ($isNullable === '' || $isNullable === 'y' || $isNullable === 'yes');

                $fields[] = [
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'length' => $fieldLength ?: null,
                    'comment' => $fieldComment,
                    'is_primary' => $isPrimary,
                    'is_nullable' => $isNullable
                ];

                $this->printer->success(__('字段 %{1} 已添加', [$fieldName]));

                $this->printer->note(__('是否继续添加字段？(y/n，默认：n)'));
                $continue = trim(strtolower($this->system->input()));
                if ($continue !== 'y' && $continue !== 'yes') {
                    break;
                }

                $fieldIndex++;
            }
        }

        $this->moduleConfig['models'][] = [
            'name' => $modelName,
            'fields' => $fields,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->printer->success(__('模型 %{1} 已添加到配置', [$modelName]));
        
        // 立即保存配置
        $this->saveConfig();
    }

    /**
     * 创建服务（会立即保存配置）
     */
    private function createService(string $moduleName): void
    {
        $this->printer->note(__('请输入服务名称（例如：ProductService 或 Product，生成时会自动添加Service后缀）'));
        $serviceName = trim($this->system->input());
        
        if (empty($serviceName)) {
            $this->printer->warning(__('服务名称不能为空'));
            return;
        }

        // 如果名称以Service结尾，移除它（生成时会自动添加）
        if (str_ends_with($serviceName, 'Service')) {
            $serviceName = substr($serviceName, 0, -7);
            $this->printer->note(__('已移除Service后缀，生成时会自动添加'));
        }

        // 保存原始输入和最终名称
        $finalServiceName = $serviceName . 'Service';

        $this->moduleConfig['services'][] = [
            'name' => $serviceName,
            'final_name' => $finalServiceName,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->printer->success(__('服务 %{1} 已添加到配置（将生成为 %{2}）', [$serviceName, $finalServiceName]));
        
        // 立即保存配置
        $this->saveConfig();
    }

    /**
     * 提取并翻译i18n词典
     * @param string $moduleName 模块名
     * @param array $languages 要翻译的语言列表，例如：['zh_Hans_CN', 'en_US']
     */
    private function extractAndTranslateI18n(string $moduleName, array $languages = ['zh_Hans_CN']): void
    {
        $this->printer->note(__('=== 提取并翻译i18n词典 ==='));
        $this->printer->note(__('目标语言：%{1}', [implode(', ', $languages)]));
        
        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        
        // 检查模块目录是否存在
        if (!is_dir($modulePath)) {
            $this->printer->warning(__('模块目录不存在，请先生成模块'));
            return;
        }

        // 1. 提取需要翻译的字符串
        $this->printer->note(__('正在扫描模块文件，提取需要翻译的字符串...'));
        $stringsToTranslate = $this->extractI18nStrings($modulePath);
        
        if (empty($stringsToTranslate)) {
            $this->printer->warning(__('未找到需要翻译的字符串'));
            return;
        }

        $this->printer->success(__('找到 %{1} 个需要翻译的字符串', [count($stringsToTranslate)]));

        // 2. 为每个语言生成翻译
        $allTranslatedStrings = []; // [语言 => [原文 => 译文]]
        $allUntranslatedStrings = []; // [语言 => [原文 => 上下文]]
        
        foreach ($languages as $targetLanguage) {
            $this->printer->printing("\n");
            $this->printer->note(__('=== 处理语言：%{1} ===', [$targetLanguage]));
            
            $translatedStrings = [];
            $untranslatedStrings = [];
            
            // 2.1 尝试使用AI翻译服务（增量翻译，边翻译边保存）
            $this->printer->note(__('尝试使用AI翻译服务...'));
            $aiTranslated = $this->translateWithAI($stringsToTranslate, 'zh_Hans_CN', $targetLanguage, $modulePath, $moduleName);
            
            if (!empty($aiTranslated)) {
                $translatedStrings = array_merge($translatedStrings, $aiTranslated);
                $this->printer->success(__('AI翻译了 %{1} 个字符串', [count($aiTranslated)]));
            }

            // 2.2 从数据库词典中查找已翻译的内容
            $remainingStrings = array_diff_key($stringsToTranslate, $translatedStrings);
            if (!empty($remainingStrings)) {
                $this->printer->note(__('从数据库词典中查找已翻译的内容...'));
                $dbTranslated = $this->translateFromDatabase($remainingStrings, $targetLanguage);
                
                if (!empty($dbTranslated)) {
                    $translatedStrings = array_merge($translatedStrings, $dbTranslated);
                    $this->printer->success(__('数据库词典翻译了 %{1} 个字符串', [count($dbTranslated)]));
                }
            }

            // 2.3 确定未翻译的字符串
            $untranslatedStrings = array_diff_key($stringsToTranslate, $translatedStrings);
            
            $allTranslatedStrings[$targetLanguage] = $translatedStrings;
            $allUntranslatedStrings[$targetLanguage] = $untranslatedStrings;
        }

        // 3. 生成i18n文件
        $this->generateI18nFiles($modulePath, $moduleName, $allTranslatedStrings, $allUntranslatedStrings, $languages);

        // 4. 更新配置
        if (!isset($this->moduleConfig['i18n'])) {
            $this->moduleConfig['i18n'] = [];
        }
        $totalTranslated = 0;
        $totalUntranslated = 0;
        foreach ($languages as $lang) {
            $totalTranslated += count($allTranslatedStrings[$lang] ?? []);
            $totalUntranslated += count($allUntranslatedStrings[$lang] ?? []);
        }
        $this->moduleConfig['i18n'][] = [
            'extracted_at' => date('Y-m-d H:i:s'),
            'languages' => $languages,
            'total_strings' => count($stringsToTranslate),
            'translated_strings' => $totalTranslated,
            'untranslated_strings' => $totalUntranslated
        ];
        $this->saveConfig();

        // 5. 显示结果
        $this->printer->printing("\n");
        $this->printer->note(__('=== 翻译结果 ==='));
        $this->printer->printing("总字符串数: " . count($stringsToTranslate) . "\n");
        foreach ($languages as $lang) {
            $translatedCount = count($allTranslatedStrings[$lang] ?? []);
            $untranslatedCount = count($allUntranslatedStrings[$lang] ?? []);
            $this->printer->printing("语言 {$lang} - 已翻译: {$translatedCount}, 未翻译: {$untranslatedCount}\n");
        }
        
        // 显示未翻译的字符串（只显示第一个语言的）
        if (!empty($languages) && !empty($allUntranslatedStrings[$languages[0]])) {
            $untranslatedStrings = $allUntranslatedStrings[$languages[0]];
            $this->printer->printing("\n");
            $this->printer->warning(__('以下字符串需要手动翻译：'));
            foreach (array_slice($untranslatedStrings, 0, 20) as $original => $context) {
                $this->printer->printing("  - {$original}\n");
            }
            if (count($untranslatedStrings) > 20) {
                $this->printer->note(__('... 还有 %{1} 个字符串未显示', [count($untranslatedStrings) - 20]));
            }
            $this->printer->note(__('请手动编辑 i18n 文件进行翻译'));
        }
    }

    /**
     * 提取需要翻译的字符串
     * 使用统一的 I18n 收集服务
     */
    private function extractI18nStrings(string $modulePath): array
    {
        $collector = ObjectManager::getInstance(\Weline\I18n\Service\TranslationCollector::class);
        $moduleName = str_replace(DS, '_', str_replace(APP_CODE_PATH, '', $modulePath));
        return $collector->collect($modulePath, $moduleName);
    }
    
    /**
     * 验证字符串是否为有效的翻译字符串
     * 使用统一的 I18n 收集服务
     * @param string $str
     * @return bool
     */
    private function isValidTranslationString(string $str): bool
    {
        $collector = ObjectManager::getInstance(\Weline\I18n\Service\TranslationCollector::class);
        return $collector->isValidTranslationString($str);
    }

    /**
     * 扫描PHP文件
     */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('扫描PHP文件时出错：%{1}', [$e->getMessage()]));
        }
        
        return $files;
    }

    /**
     * 扫描PHTML文件
     */
    private function scanPhtmlFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'phtml') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('扫描PHTML文件时出错：%{1}', [$e->getMessage()]));
        }
        
        return $files;
    }

    /**
     * 使用AI翻译服务
     * @param array $strings 要翻译的字符串 [原文 => 上下文]
     * @param string $sourceLanguage 源语言，默认 'zh_Hans_CN'
     * @param string $targetLanguage 目标语言，默认 'en_US'
     * @param string $modulePath 模块路径，用于增量保存
     * @param string $moduleName 模块名称，用于增量保存
     * @return array [原文 => 译文]
     */
    private function translateWithAI(
        array $strings, 
        string $sourceLanguage = 'zh_Hans_CN', 
        string $targetLanguage = 'en_US',
        string $modulePath = '',
        string $moduleName = ''
    ): array {
        $translated = [];
        
        try {
            // 使用 TranslationService 进行翻译（这是标准方式）
            if (!class_exists('Weline\Ai\Service\TranslationService')) {
                $this->printer->warning(__('AI模块未找到，跳过AI翻译'));
                return $translated;
            }
            
            // 手动创建 TranslationService 实例，因为需要正确的依赖注入
            $aiService = ObjectManager::getInstance('Weline\Ai\Service\AiService');
            // 使用 CacheFactory 创建缓存实例
            $cacheFactory = new \Weline\Framework\Cache\CacheFactory('translation', __('翻译缓存'), false);
            $cache = $cacheFactory->create();
            $i18nIntegration = ObjectManager::getInstance('Weline\Ai\Service\I18nIntegration');
            $defaultModelManager = ObjectManager::getInstance('Weline\Ai\Service\DefaultModelManager');
            
            /** @var \Weline\Ai\Service\TranslationService $translationService */
            $translationService = new \Weline\Ai\Service\TranslationService(
                $aiService,
                $cache,
                $i18nIntegration,
                $defaultModelManager
            );
            
            $this->printer->note(__('找到AI翻译服务，开始翻译...'));
            
            // 加载现有翻译文件，用于增量翻译和过滤已翻译的
            $existingTranslations = [];
            $languageFile = '';
            if ($modulePath && $moduleName) {
                $i18nDir = $modulePath . DS . 'i18n';
                if (is_dir($i18nDir)) {
                    $languageFile = $i18nDir . DS . $targetLanguage . '.csv';
                    if (file_exists($languageFile)) {
                        $existingTranslations = $this->loadExistingI18nFile($languageFile);
                    }
                }
            }
            
            // 过滤：只翻译未翻译的（词和译文相同的记录）
            $stringsToTranslate = [];
            foreach ($strings as $original => $context) {
                // 检查是否已存在翻译，且翻译和原文不同（已翻译）
                if (isset($existingTranslations[$original])) {
                    $existingTranslation = $existingTranslations[$original];
                    // 如果翻译和原文相同，说明未翻译，需要翻译
                    if ($existingTranslation === $original || empty($existingTranslation)) {
                        $stringsToTranslate[$original] = $context;
                    }
                    // 如果翻译和原文不同，说明已翻译，跳过
                } else {
                    // 不存在，需要翻译
                    $stringsToTranslate[$original] = $context;
                }
            }
            
            if (empty($stringsToTranslate)) {
                $this->printer->success(__('所有字符串都已翻译，无需AI翻译'));
                return $translated;
            }
            
            $this->printer->note(__('需要翻译 %{1} 个字符串（已过滤已翻译的）', [count($stringsToTranslate)]));
            
            // 使用批量翻译（每次翻译50个，避免超时）
            $batchSize = 50;
            $stringArray = array_keys($stringsToTranslate);
            $batches = array_chunk($stringArray, $batchSize);
            $totalBatches = count($batches);
            $currentBatch = 0;
            $translatedCount = 0;
            $failedCount = 0;
            $lastBatch = null;
            $lastBatchTranslations = null;
            
            foreach ($batches as $batch) {
                $currentBatch++;
                // 显示进度条（使用 \r 覆盖当前行，避免刷屏）
                $percentage = round(($currentBatch / $totalBatches) * 100, 1);
                $barLength = 50;
                $filled = (int)round(($currentBatch / $totalBatches) * $barLength);
                $empty = $barLength - $filled;
                $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
                $progressBar = sprintf(
                    '翻译进度: [%s] %d/%d (%.1f%%) - 成功: %d',
                    $bar,
                    $currentBatch,
                    $totalBatches,
                    $percentage,
                    $translatedCount
                );
                // 使用 echo 直接输出，确保进度条实时更新
                echo "\r" . $progressBar;
                // 刷新输出缓冲区
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                
                try {
                    // 使用 TranslationService 的批量翻译方法
                    // 注意：batchTranslate 接收的数组键值对应关系：键是索引，值是原文
                    // 返回的数组保持相同的键值对应关系
                    $batchTranslations = $translationService->batchTranslate(
                        $batch,
                        $targetLanguage,
                        $sourceLanguage
                    );
                    
                    // 保存最后一批的结果用于调试
                    $lastBatch = $batch;
                    $lastBatchTranslations = $batchTranslations;
                    
                    // 处理批量翻译结果，并立即保存
                    $batchTranslated = [];
                    foreach ($batch as $index => $original) {
                        if (isset($batchTranslations[$index])) {
                            $translation = $batchTranslations[$index];
                            $translationTrimmed = trim($translation);
                            
                            // 验证翻译结果
                            // 注意：如果翻译失败，batchTranslate 会返回原文，所以需要检查是否真的翻译了
                            if (!empty($translationTrimmed) && $translationTrimmed !== $original) {
                                // 清理翻译结果（移除可能的引号、换行等）
                                $translationTrimmed = trim($translationTrimmed, " \t\n\r\0\x0B\"'");
                                if (!empty($translationTrimmed) && $translationTrimmed !== $original) {
                                    $translated[$original] = $translationTrimmed;
                                    $batchTranslated[$original] = $translationTrimmed;
                                    $translatedCount++;
                                } else {
                                    // 清理后和原文相同，视为失败
                                    $failedCount++;
                                }
                            } else {
                                // 翻译结果和原文相同，可能是翻译失败返回了原文
                                $failedCount++;
                            }
                        } else {
                            // 没有返回翻译结果
                            $failedCount++;
                        }
                    }
                    
                    // 立即保存这一批的翻译结果（增量保存）
                    if (!empty($batchTranslated) && $languageFile) {
                        $this->saveBatchTranslations($languageFile, $batchTranslated, $existingTranslations);
                        // 更新现有翻译，避免重复保存
                        $existingTranslations = array_merge($existingTranslations, $batchTranslated);
                    }
                    
                } catch (\Exception $e) {
                    // 批量翻译失败，记录错误但继续下一批
                    $failedCount += count($batch);
                    // 输出错误信息到控制台
                    $this->printer->error(__('批量翻译异常：%{1}', [$e->getMessage()]));
                }
            }
            
            // 翻译完成后，换行并显示结果
            echo "\n";
            
            if (!empty($translated)) {
                $this->printer->success(__('AI翻译完成，成功翻译 %{1} 个字符串', [count($translated)]));
            } else {
                // 如果全部失败，显示调试信息
                $totalProcessed = $translatedCount + $failedCount;
                $this->printer->warning(__('AI翻译完成，但没有成功翻译任何字符串（共处理 %{1} 个）', [$totalProcessed]));
                $this->printer->note(__('可能的原因：'));
                $this->printer->note(__('  1. 翻译结果和原文相同（AI返回了原文）'));
                $this->printer->note(__('  2. AI服务调用失败或返回错误'));
                $this->printer->note(__('  3. 适配器参数验证失败'));
                $this->printer->note(__('  4. 翻译模型未配置或未激活'));
                
                // 显示一个示例，帮助调试（从最后一批中取一个）
                if (!empty($lastBatch) && !empty($lastBatchTranslations)) {
                    $firstOriginal = $lastBatch[0] ?? '';
                    $firstTranslation = $lastBatchTranslations[0] ?? '';
                    if ($firstOriginal && $firstTranslation) {
                        $this->printer->note(__('示例：原文 "%{1}" -> 翻译结果 "%{2}"', [
                            mb_substr($firstOriginal, 0, 50) . (mb_strlen($firstOriginal) > 50 ? '...' : ''),
                            mb_substr($firstTranslation, 0, 50) . (mb_strlen($firstTranslation) > 50 ? '...' : '')
                        ]));
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->printer->warning(__('AI翻译服务调用失败：%{1}', [$e->getMessage()]));
        }
        
        return $translated;
    }
    
    /**
     * 保存批量翻译结果（增量保存）
     * 
     * @param string $languageFile 语言文件路径
     * @param array $batchTranslated 批量翻译结果 [原文 => 译文]
     * @param array $existingTranslations 现有翻译 [原文 => 译文]
     * @return void
     */
    private function saveBatchTranslations(string $languageFile, array $batchTranslated, array $existingTranslations): void
    {
        try {
            // 合并现有翻译和新翻译
            $allTranslations = array_merge($existingTranslations, $batchTranslated);
            
            // 写入文件
            $this->writeI18nFile($languageFile, $allTranslations);
        } catch (\Exception $e) {
            // 保存失败不影响翻译流程，只记录警告
            $this->printer->warning(__('保存翻译结果失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 从数据库词典中查找已翻译的内容
     * @param array $strings 要翻译的字符串 [原文 => 上下文]
     * @param string $targetLanguage 目标语言，默认 'en_US'
     * @return array [原文 => 译文]
     */
    private function translateFromDatabase(array $strings, string $targetLanguage = 'en_US'): array
    {
        $translated = [];
        
        try {
            // 使用本地词典模型
            if (!class_exists('Weline\I18n\Model\Locale\Dictionary')) {
                $this->printer->note(__('本地词典模型未找到，跳过数据库翻译'));
                return $translated;
            }
            
            /** @var \Weline\I18n\Model\Locale\Dictionary $dictionaryModel */
            $dictionaryModel = ObjectManager::getInstance('Weline\I18n\Model\Locale\Dictionary');
            
            $originalStrings = array_keys($strings);
            
            // 批量查询（每次查询50个）
            $batchSize = 50;
            $batches = array_chunk($originalStrings, $batchSize);
            
            foreach ($batches as $batch) {
                try {
                    $results = $dictionaryModel->reset()
                        ->where(\Weline\I18n\Model\Locale\Dictionary::fields_WORD, $batch, 'IN')
                        ->where(\Weline\I18n\Model\Locale\Dictionary::fields_LOCALE_CODE, $targetLanguage)
                        ->fetch()
                        ->getItems();
                    
                    if (!empty($results)) {
                        foreach ($results as $result) {
                            $word = $result->getData(\Weline\I18n\Model\Locale\Dictionary::fields_WORD) ?? '';
                            $translation = $result->getData(\Weline\I18n\Model\Locale\Dictionary::fields_TRANSLATE) ?? '';
                            if (!empty($word) && !empty($translation)) {
                                $translated[$word] = $translation;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 查询失败，继续下一个批次
                    continue;
                }
            }
            
        } catch (\Exception $e) {
            $this->printer->note(__('数据库翻译查询失败：%{1}', [$e->getMessage()]));
        }
        
        return $translated;
    }

    /**
     * 生成i18n文件
     * @param string $modulePath 模块路径
     * @param string $moduleName 模块名
     * @param array $allTranslatedStrings [语言 => [原文 => 译文]]
     * @param array $allUntranslatedStrings [语言 => [原文 => 上下文]]
     * @param array $languages 语言列表
     */
    private function generateI18nFiles(string $modulePath, string $moduleName, array $allTranslatedStrings, array $allUntranslatedStrings, array $languages): void
    {
        $i18nPath = $modulePath . DS . 'i18n';
        if (!is_dir($i18nPath)) {
            mkdir($i18nPath, 0755, true);
        }

        // 为每个语言生成文件
        foreach ($languages as $language) {
            $translatedStrings = $allTranslatedStrings[$language] ?? [];
            $untranslatedStrings = $allUntranslatedStrings[$language] ?? [];
            
            $languageFile = $i18nPath . DS . $language . '.csv';
            $existingData = $this->loadExistingI18nFile($languageFile);
            
            // 合并已有翻译和新翻译
            $languageData = $existingData;
            $newCount = 0;
            $updatedCount = 0;
            
            // 判断是否为源语言（zh_Hans_CN），源语言原文和译文相同
            $isSourceLanguage = ($language === 'zh_Hans_CN' || $language === 'zh_CN');
            
            // 添加已翻译的字符串
            foreach ($translatedStrings as $original => $translation) {
                if (!isset($languageData[$original])) {
                    if ($isSourceLanguage) {
                        $languageData[$original] = $original; // 源语言原文和译文相同
                    } else {
                        $languageData[$original] = $translation;
                    }
                    $newCount++;
                } else {
                    // 如果已有翻译，使用新的AI翻译更新（如果新翻译不为空且不是源语言）
                    if (!$isSourceLanguage && !empty($translation) && $translation !== $original) {
                        $languageData[$original] = $translation;
                        $updatedCount++;
                    }
                }
            }
            
            // 添加未翻译的字符串（原文作为占位符）
            foreach ($untranslatedStrings as $original => $context) {
                if (!isset($languageData[$original])) {
                    $languageData[$original] = $original;
                    $newCount++;
                }
            }
            
            // 写入文件
            $this->writeI18nFile($languageFile, $languageData);
            if ($newCount > 0 || $updatedCount > 0) {
                if ($updatedCount > 0) {
                    $this->printer->success(__('已更新%{1}词典文件：%{2}（新增：%{3}，更新：%{4}）', [$language, $language . '.csv', $newCount, $updatedCount]));
                } else {
                    $this->printer->success(__('已更新%{1}词典文件：%{2}（新增：%{3}）', [$language, $language . '.csv', $newCount]));
                }
            } else {
                $this->printer->note(__('%{1}词典文件无需更新：%{2}', [$language, $language . '.csv']));
            }
            
            if (!$isSourceLanguage && !empty($untranslatedStrings)) {
                $this->printer->note(__('提示：%{1} 中包含未翻译的字符串，请手动编辑进行翻译', [$language . '.csv']));
            }
        }
    }
    
    /**
     * 加载现有的i18n文件
     * @param string $filePath
     * @return array [原文 => 译文]
     */
    private function loadExistingI18nFile(string $filePath): array
    {
        $data = [];
        
        if (!file_exists($filePath)) {
            return $data;
        }
        
        $content = file_get_contents($filePath);
        if (empty($content)) {
            return $data;
        }
        
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // 解析CSV行：使用str_getcsv处理CSV格式
            // 参数：$line, $separator=',', $enclosure='"', $escape='\\'
            $parsed = str_getcsv($line, ',', '"', '\\');
            if (count($parsed) >= 2) {
                $original = $parsed[0];
                $translation = $parsed[1];
                $data[$original] = $translation;
            }
        }
        
        return $data;
    }
    
    /**
     * 写入i18n文件
     * @param string $filePath
     * @param array $data [原文 => 译文]
     */
    private function writeI18nFile(string $filePath, array $data): void
    {
        $content = [];
        foreach ($data as $original => $translation) {
            // 转义CSV中的引号和换行符
            $escapedOriginal = '"' . str_replace('"', '""', $original) . '"';
            $escapedTranslation = '"' . str_replace('"', '""', $translation) . '"';
            $content[] = $escapedOriginal . ',' . $escapedTranslation;
        }
        
        file_put_contents($filePath, implode("\n", $content));
    }

    /**
     * 生成模块
     */
    private function generateModule(string $moduleName, array $controllers): void
    {
        $this->printer->note(__('开始生成模块...'));

        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        $namespace = str_replace('_', '\\', $moduleName);

        // 创建模块目录
        if (!is_dir($modulePath)) {
            mkdir($modulePath, 0755, true);
            $this->printer->success(__('已创建模块目录：%{1}', [$modulePath]));
        }

        // 创建 register.php
        $this->createRegisterFile($modulePath, $moduleName);

        // 创建 etc/env.php（如果配置了路由）
        $router = $this->moduleConfig['router'] ?? '';
        if (!empty($router) && $this->moduleConfig['env_configured']) {
            $this->createEnvFile($modulePath, $router);
        }

        // 创建控制器
        foreach ($controllers as $controller) {
            $this->createController($modulePath, $namespace, $controller);
        }

        // 创建事件配置（如果有）
        if (!empty($this->moduleConfig['events'])) {
            $this->createEventConfig($modulePath);
        }

        // 创建插件配置（如果有）
        if (!empty($this->moduleConfig['plugins'])) {
            $this->createPluginConfig($modulePath, $namespace);
        }
        
        // 询问是否注册模块到系统
        $this->printer->printing("\n");
        $this->printer->note(__('模块文件生成完成！'));
        $this->printer->note(__('是否立即注册模块到系统？(y/n，默认：y)'));
        $registerInput = trim(strtolower($this->system->input()));
        
        if ($registerInput !== 'n' && $registerInput !== 'no') {
            $this->registerModule($moduleName, $modulePath);
        } else {
            $this->printer->note(__('提示：您可以稍后运行以下命令注册模块：'));
            $this->printer->printing("  php bin/w setup:upgrade\n");
        }

        // 如果配置文件在var目录，迁移到模块根目录
        $oldConfigFile = BP . 'var' . DS . 'module_config' . DS . str_replace('_', DS, $moduleName) . '.json';
        $newConfigFile = $modulePath . DS . '.module_config.json';
        if (file_exists($oldConfigFile) && !file_exists($newConfigFile)) {
            // 迁移配置文件到模块根目录
            $oldConfig = json_decode(file_get_contents($oldConfigFile), true) ?? [];
            if (!empty($oldConfig)) {
                file_put_contents($newConfigFile, json_encode($oldConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->printer->note(__('已迁移配置文件到模块根目录：%{1}', ['.module_config.json']));
                // 更新configFile路径
                $this->configFile = $newConfigFile;
            }
        } elseif (!file_exists($newConfigFile) && !empty($this->moduleConfig)) {
            // 如果配置文件不存在，保存到模块根目录
            $this->configFile = $newConfigFile;
            $this->saveConfig();
        }

        $this->printer->success(__('模块生成完成！'));
    }

    /**
     * 注册模块到系统
     */
    private function registerModule(string $moduleName, string $modulePath): void
    {
        $this->printer->setup(__('正在注册模块到系统...'));
        
        $registerFile = $modulePath . DS . 'register.php';
        if (!file_exists($registerFile)) {
            $this->printer->error(__('模块注册文件不存在：%{1}', [$registerFile]));
            return;
        }
        
        try {
            // 1. 先清除缓存，确保能识别新模块
            $this->printer->note(__('正在清除缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            
            // 2. 执行注册文件
            require_once $registerFile;
            
            // 3. 运行 setup:upgrade 来刷新模块列表（指定模块）
            $this->printer->note(__('正在刷新模块列表...'));
            $command = 'php ' . BP . 'bin' . DS . 'w setup:upgrade ' . escapeshellarg($moduleName);
            $output = [];
            $returnVar = 0;
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->printer->success(__('模块 %{1} 已成功注册到系统！', [$moduleName]));
            } else {
                $this->printer->warning(__('模块注册可能未完全完成，请手动运行：php bin/w setup:upgrade %{1}', [$moduleName]));
                if (!empty($output)) {
                    $this->printer->printing(implode("\n", $output) . "\n");
                }
            }
        } catch (\Exception $e) {
            $this->printer->error(__('注册模块时出错：%{1}', [$e->getMessage()]));
            $this->printer->note(__('请手动运行以下命令注册模块：'));
            $this->printer->printing("  php bin/w setup:upgrade {$moduleName}\n");
        }
    }

    /**
     * 检查文件是否存在并询问是否覆盖
     */
    private function checkFileExists(string $filePath, string $fileName): bool
    {
        // 检查目录是否存在，如果目录都不存在，文件肯定不存在
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            return true; // 目录不存在，文件肯定不存在，可以创建
        }

        if (file_exists($filePath)) {
            $this->printer->warning(__('文件 %{1} 已存在，不能覆盖', [$fileName]));
            $this->printer->note(__('文件路径：%{1}', [$filePath]));
            $this->printer->note(__('请手动前往修改，或选择覆盖（将删除现有文件）'));
            $this->printer->note(__('是否要覆盖？(y/n，默认：n)'));
            $answer = trim(strtolower($this->system->input()));
            if ($answer === 'y' || $answer === 'yes') {
                return true; // 允许覆盖
            }
            return false; // 不允许覆盖，跳过
        }
        return true; // 文件不存在，可以创建
    }

    /**
     * 创建 register.php
     */
    private function createRegisterFile(string $modulePath, string $moduleName): void
    {
        $registerFile = $modulePath . DS . 'register.php';
        if (!$this->checkFileExists($registerFile, 'register.php')) {
            return;
        }

        $version = $this->moduleConfig['version'] ?? '1.0.0';
        $description = $this->moduleConfig['description'] ?? '';
        $moduleNameEscaped = addslashes($moduleName);
        $versionEscaped = addslashes($version);
        $descriptionEscaped = addslashes($description);

        $fp = fopen($registerFile, 'w');
        if ($fp) {
            fwrite($fp, "<?php\n\n");
            fwrite($fp, "/*\n");
            fwrite($fp, " * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。\n");
            fwrite($fp, " * 邮箱：aiweline@qq.com\n");
            fwrite($fp, " * 网址：aiweline.com\n");
            fwrite($fp, " * 论坛：https://bbs.aiweline.com\n");
            fwrite($fp, " */\n\n");
            fwrite($fp, "use Weline\\Framework\\Register\\Register;\n\n");
            fwrite($fp, "Register::register(\n");
            fwrite($fp, "    Register::MODULE,\n");
            fwrite($fp, "    '{$moduleNameEscaped}',\n");
            fwrite($fp, "    __DIR__,\n");
            fwrite($fp, "    '{$versionEscaped}',\n");
            fwrite($fp, "    '{$descriptionEscaped}',\n");
            fwrite($fp, "    ['Weline_Framework']\n");
            fwrite($fp, ");");
            fclose($fp);
        }
        $this->printer->success(__('已创建 register.php'));
    }

    /**
     * 创建 env.php
     */
    private function createEnvFile(string $modulePath, string $router): void
    {
        $etcPath = $modulePath . DS . 'etc';
        if (!is_dir($etcPath)) {
            mkdir($etcPath, 0755, true);
        }

        $envFile = $etcPath . DS . 'env.php';
        if (!$this->checkFileExists($envFile, 'env.php')) {
            return;
        }

        $routerEscaped = addslashes($router);
        $content = "<?php\n\n";
        $content .= "/*\n";
        $content .= " * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。\n";
        $content .= " * 邮箱：aiweline@qq.com\n";
        $content .= " * 网址：aiweline.com\n";
        $content .= " * 论坛：https://bbs.aiweline.com\n";
        $content .= " */\n\n";
        $content .= "return [\n";
        $content .= "    'router' => '{$routerEscaped}',\n";
        $content .= "    'dependencies' => []\n";
        $content .= "];";

        file_put_contents($envFile, $content);
        $this->printer->success(__('已创建 env.php'));
    }

    /**
     * 创建控制器
     */
    private function createController(string $modulePath, string $namespace, array $controller): void
    {
        // 根据类型确定目录结构
        $type = $controller['type'];
        $name = $controller['name'];
        
        // 支持4种控制器类型：Frontend、Backend、FrontendApi、BackendApi
        if ($type === 'FrontendApi' || $type === 'Api') {
            // 前端API控制器：创建在 Api/Rest/V1/Frontend 目录
            $controllerDir = $modulePath . DS . 'Api' . DS . 'Rest' . DS . 'V1' . DS . 'Frontend';
            $baseClass = 'FrontendRestController';
        } elseif ($type === 'BackendApi') {
            // 后端API控制器：创建在 Api/Rest/V1/Backend 目录（所有API都在Api目录下，通过子目录区分）
            $controllerDir = $modulePath . DS . 'Api' . DS . 'Rest' . DS . 'V1' . DS . 'Backend';
            $baseClass = 'BackendRestController';
        } elseif ($type === 'Backend') {
            // 后端控制器：创建在 Controller/Backend 目录
            $controllerDir = $modulePath . DS . 'Controller' . DS . 'Backend';
            $baseClass = 'BackendController';
        } else {
            // Frontend 控制器：创建在 Controller/Frontend 目录
            $controllerDir = $modulePath . DS . 'Controller' . DS . 'Frontend';
            $baseClass = 'FrontendController';
        }

        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0755, true);
        }

        $controllerFile = $controllerDir . DS . $name . '.php';
        if (!$this->checkFileExists($controllerFile, "控制器 {$name}.php")) {
            return;
        }

        // 根据类型确定命名空间
        if ($type === 'FrontendApi' || $type === 'Api') {
            // 前端API控制器：命名空间为 Api\Rest\V1\Frontend
            $controllerNamespace = $namespace . '\\Api\\Rest\\V1\\Frontend';
        } elseif ($type === 'BackendApi') {
            // 后端API控制器：命名空间为 Api\Rest\V1\Backend（所有API都在Api命名空间下，通过子命名空间区分）
            $controllerNamespace = $namespace . '\\Api\\Rest\\V1\\Backend';
        } elseif ($type === 'Backend') {
            // 后端控制器：命名空间为 Controller\Backend
            $controllerNamespace = $namespace . '\\Controller\\Backend';
        } else {
            // Frontend 控制器：命名空间为 Controller\Frontend
            $controllerNamespace = $namespace . '\\Controller\\Frontend';
        }

        // 获取Acl信息
        $aclInfo = $controller['acl'] ?? null;
        $classAcl = $aclInfo['class'] ?? null;
        $methodAcl = $aclInfo['method'] ?? null;
        
        // 构建Acl注解代码
        $classAclAnnotation = '';
        $methodAclAnnotation = '';
        $useAclStatement = '';
        
        if ($classAcl) {
            $useAclStatement = "use Weline\Framework\Acl\Acl;\n";
            $classSourceId = addslashes($classAcl['source_id'] ?? '');
            $classSourceName = addslashes($classAcl['source_name'] ?? '');
            $classIcon = addslashes($classAcl['icon'] ?? '');
            $classDocument = addslashes($classAcl['document'] ?? '');
            $classAclAnnotation = "#[Acl('{$classSourceId}', '{$classSourceName}', '{$classIcon}', '{$classDocument}')]\n";
        }
        
        if ($methodAcl) {
            if (empty($useAclStatement)) {
                $useAclStatement = "use Weline\Framework\Acl\Acl;\n";
            }
            $methodSourceId = addslashes($methodAcl['source_id'] ?? '');
            $methodSourceName = addslashes($methodAcl['source_name'] ?? '');
            $methodIcon = addslashes($methodAcl['icon'] ?? '');
            $methodDocument = addslashes($methodAcl['document'] ?? '');
            $parentSource = $classAcl ? addslashes($classAcl['source_id'] ?? '') : '';
            if ($type === 'BackendApi' && !empty($parentSource)) {
                $methodAclAnnotation = "    #[Acl('{$methodSourceId}', '{$methodSourceName}', '{$methodIcon}', '{$methodDocument}', '{$parentSource}')]\n";
            } else {
                $methodAclAnnotation = "    #[Acl('{$methodSourceId}', '{$methodSourceName}', '{$methodIcon}', '{$methodDocument}')]\n";
            }
        }
        
        // 根据控制器类型生成不同的代码
        if ($type === 'FrontendApi' || $type === 'Api' || $type === 'BackendApi') {
            // API控制器返回JSON
            $content = <<<PHP
<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace {$controllerNamespace};

use Weline\Framework\App\Controller\\{$baseClass};
{$useAclStatement}
{$classAclAnnotation}class {$name} extends {$baseClass}
{
{$methodAclAnnotation}    public function index()
    {
        return \$this->fetch([
            'code' => 200,
            'msg' => 'success',
            'data' => ['message' => 'Hello from {$name}']
        ]);
    }
}
PHP;
        } else {
            // 普通控制器返回视图
            $content = <<<PHP
<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace {$controllerNamespace};

use Weline\Framework\App\Controller\\{$baseClass};
{$useAclStatement}
{$classAclAnnotation}class {$name} extends {$baseClass}
{
{$methodAclAnnotation}    public function index()
    {
        \$this->assign('message', 'Hello from {$name}');
        return \$this->fetch();
    }
}
PHP;
        }

        file_put_contents($controllerFile, $content);
        $this->printer->success(__('已创建控制器：%{1}', [$name]));
        
        // 根据控制器类型显示Acl配置提示
        if ($type === 'Backend') {
            $this->printer->printing("\n");
            $this->printer->warning(__('⚠️  请记住：后端控制器必须配置Acl权限注解才能被菜单识别和访问控制！'));
            $this->printer->note(__('   请在控制器类和方法上添加Acl注解，然后重新运行菜单创建命令。'));
        } elseif ($type === 'BackendApi') {
            $this->printer->printing("\n");
            $this->printer->warning(__('⚠️  请记住：后端API控制器必须配置Acl权限注解！'));
            $this->printer->note(__('   请在控制器类和方法上添加Acl注解。'));
        }

        // 自动创建对应的视图模板（非API控制器才需要视图）
        if ($type !== 'FrontendApi' && $type !== 'BackendApi' && $type !== 'Api') {
            $this->createViewTemplate($modulePath, $type, $name);
        }
    }

    /**
     * 创建默认视图
     */
    private function createDefaultView(string $modulePath, string $controllerName): void
    {
        $viewPath = $modulePath . DS . 'view' . DS . 'templates' . DS . 'Frontend' . DS . $controllerName;
        if (!is_dir($viewPath)) {
            mkdir($viewPath, 0755, true);
        }

        $viewFile = $viewPath . DS . 'index.phtml';
        if (!$this->checkFileExists($viewFile, "视图文件 index.phtml")) {
            return;
        }

        $content = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Hello World</title>
</head>
<body>
    <h1>Hello World!</h1>
    <p>这是默认的 Hello World 页面。</p>
</body>
</html>
HTML;

        file_put_contents($viewFile, $content);
        $this->printer->success(__('已创建默认视图文件'));
    }

    /**
     * 创建视图模板
     */
    private function createViewTemplate(string $modulePath, string $controllerType, string $controllerName): void
    {
        // 确定视图目录
        $area = 'Frontend';
        if ($controllerType === 'Backend') {
            $area = 'Backend';
        }
        
        $viewPath = $modulePath . DS . 'view' . DS . 'templates' . DS . $area . DS . $controllerName;
        if (!is_dir($viewPath)) {
            mkdir($viewPath, 0755, true);
        }

        $viewFile = $viewPath . DS . 'index.phtml';
        if (!$this->checkFileExists($viewFile, "视图文件 index.phtml")) {
            return;
        }

        // 根据控制器类型生成不同的视图内容
        if ($controllerType === 'Backend') {
            // 后端模板：包含后端公共模板
            $content = <<<HTML
<!DOCTYPE html>
<html lang="<?= str_replace('_', '-', \$_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN') ?>">
<head>
    @template(Weline_Backend::templates/public/head.phtml)
</head>
<body>
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{$controllerName}</h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">欢迎使用 {$controllerName}</h5>
                            <p class="card-text">这是 {$controllerName} 控制器的默认视图模板。</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @template(Weline_Backend::templates/public/footer.phtml)
</body>
</html>
HTML;
        } else {
            // 前端模板：包含前端公共模板
            $content = <<<HTML
<!DOCTYPE html>
<html lang="<?= str_replace('_', '-', \$_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN') ?>">
<head>
    @template(Weline_Frontend::templates/public/head.phtml)
</head>
<body>
    @template(Weline_Frontend::templates/public/header.phtml)
    
    <main class="main-content">
        <div class="container">
            <h1>欢迎使用 {$controllerName}</h1>
            <p>这是 {$controllerName} 控制器的默认视图模板。</p>
        </div>
    </main>
    
    @template(Weline_Frontend::templates/public/footer.phtml)
</body>
</html>
HTML;
        }

        file_put_contents($viewFile, $content);
        $this->printer->success(__('已创建视图模板：%{1}', [$viewFile]));
    }

    /**
     * 创建事件配置
     */
    private function createEventConfig(string $modulePath): void
    {
        $etcPath = $modulePath . DS . 'etc';
        if (!is_dir($etcPath)) {
            mkdir($etcPath, 0755, true);
        }

        $eventFile = $etcPath . DS . 'event.xml';
        if (!$this->checkFileExists($eventFile, 'event.xml')) {
            return;
        }

        $events = $this->moduleConfig['events'] ?? [];
        $observers = '';
        foreach ($events as $event) {
            $observers .= "        <observer name=\"{$event['name']}\" instance=\"{$event['name']}\"/>\n";
        }

        // 在 heredoc 外部计算事件名称，避免语法错误
        $eventName = !empty($events[0]['name']) ? $events[0]['name'] : 'default_event';

        $content = <<<XML
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="{$eventName}">
{$observers}    </event>
</config>
XML;

        file_put_contents($eventFile, $content);
        $this->printer->success(__('已创建 event.xml'));
    }

    /**
     * 创建插件配置
     */
    private function createPluginConfig(string $modulePath, string $namespace): void
    {
        $etcPath = $modulePath . DS . 'etc';
        if (!is_dir($etcPath)) {
            mkdir($etcPath, 0755, true);
        }

        $pluginFile = $etcPath . DS . 'plugin.xml';
        if (!$this->checkFileExists($pluginFile, 'plugin.xml')) {
            return;
        }

        $plugins = $this->moduleConfig['plugins'] ?? [];
        $pluginNodes = '';
        foreach ($plugins as $plugin) {
            $pluginNodes .= "        <plugin name=\"{$plugin['method']}\" type=\"{$namespace}\\Plugin\\{$plugin['method']}\" sortOrder=\"10\"/>\n";
        }

        // 在 heredoc 外部计算目标类名，避免语法错误
        $targetClass = !empty($plugins[0]['target_class']) ? $plugins[0]['target_class'] : 'default';

        $content = <<<XML
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="{$targetClass}">
{$pluginNodes}    </type>
</config>
XML;

        file_put_contents($pluginFile, $content);
        $this->printer->success(__('已创建 plugin.xml'));
    }

    /**
     * 获取配置文件路径（优先模块根目录，如果不存在则从var目录迁移）
     */
    private function getConfigFilePath(string $moduleName): string
    {
        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        $moduleConfigFile = $modulePath . DS . '.module_config.json';
        
        // 如果模块目录存在，使用模块根目录
        if (is_dir($modulePath)) {
            // 检查var目录是否有旧配置，如果有则迁移
            $oldConfigFile = BP . 'var' . DS . 'module_config' . DS . str_replace('_', DS, $moduleName) . '.json';
            if (file_exists($oldConfigFile) && !file_exists($moduleConfigFile)) {
                // 迁移旧配置到模块根目录
                $oldConfig = json_decode(file_get_contents($oldConfigFile), true) ?? [];
                if (!empty($oldConfig)) {
                    file_put_contents($moduleConfigFile, json_encode($oldConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->printer->note(__('已从 var/module_config 迁移配置文件到模块根目录'));
                    // 可选：删除旧配置文件
                    // unlink($oldConfigFile);
                }
            }
            return $moduleConfigFile;
        }
        
        // 如果模块目录不存在，检查var目录是否有配置
        $varConfigFile = BP . 'var' . DS . 'module_config' . DS . str_replace('_', DS, $moduleName) . '.json';
        if (file_exists($varConfigFile)) {
            return $varConfigFile;
        }
        
        // 默认使用模块根目录（即使目录还不存在，会在saveConfig时创建）
        return $moduleConfigFile;
    }

    /**
     * 保存配置
     */
    private function saveConfig(): void
    {
        if (empty($this->configFile)) {
            // 如果没有设置configFile，尝试从moduleConfig中获取模块名
            $moduleName = $this->moduleConfig['module_name'] ?? '';
            if (empty($moduleName)) {
                $this->printer->warning(__('无法保存配置：未找到模块名'));
                return;
            }
            $this->configFile = $this->getConfigFilePath($moduleName);
        }

        $configDir = dirname($this->configFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // 配置文件允许覆盖，但如果是已存在的配置文件，给出提示
        if (file_exists($this->configFile)) {
            $this->printer->note(__('更新配置文件：%{1}', [$this->configFile]));
        }

        $this->moduleConfig['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($this->configFile, json_encode($this->moduleConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->printer->success(__('配置已保存到：%{1}', [$this->configFile]));
    }

    /**
     * 处理二次操作
     */
    private function handleSecondaryOperation(string $moduleName): void
    {
        $modulePath = APP_CODE_PATH . str_replace('_', DS, $moduleName);
        $moduleGenerated = is_dir($modulePath) && file_exists($modulePath . DS . 'register.php');
        
        // 如果模块目录不存在，创建它
        if (!is_dir($modulePath)) {
            $this->printer->note(__('模块目录不存在，将在生成模块时创建'));
        }

        while (true) {
            $this->printer->printing("\n");
            $this->printer->note(__('=== 模块操作菜单 ==='));
            $this->printer->note(__('模块：%{1}', [$moduleName]));
            
            // 显示模块生成状态
            if ($moduleGenerated) {
                $this->printer->note(__('状态：模块已生成'));
            } else {
                $this->printer->note(__('状态：模块未生成'));
            }
            
            // 检查模块安装和启用状态
            $moduleStatus = $this->getModuleStatus($moduleName);
            if ($moduleStatus['installed']) {
                $this->printer->note(__('安装状态：已安装'));
                if ($moduleStatus['enabled']) {
                    $this->printer->success(__('启用状态：已启用'));
                } else {
                    $this->printer->warning(__('启用状态：已禁用'));
                }
            } else {
                $this->printer->note(__('安装状态：未安装'));
            }
            
            $this->printer->printing("\n");
            $this->printer->note(__('请选择操作：'));
            $this->printer->note(__('1. 新建控制器'));
            $this->printer->note(__('2. 创建目录结构（Observer、Plugin、Service等）'));
            $this->printer->note(__('3. 创建后台菜单'));
            $this->printer->note(__('4. 继承模块（Extends）'));
            $this->printer->note(__('5. 初始化主题文件到模板'));
            $this->printer->note(__('6. 查看模块配置'));
            $this->printer->note(__('7. 继续创建流程（完成未完成的步骤）'));
            $this->printer->note(__('8. 提取并翻译i18n词典'));
            $this->printer->note(__('9. 检测模块完整性'));
            $this->printer->note(__('10. 安装模块到系统'));
            $this->printer->note(__('11. 重装模块'));
            $this->printer->note(__('12. 卸载模块'));
            $this->printer->note(__('13. 回滚模块卸载'));
            if (!$moduleGenerated) {
                $this->printer->note(__('14. 生成模块（使用当前配置）'));
            }
            $this->printer->note(__('0. 退出'));

            $choice = trim($this->system->input());

            switch ($choice) {
                case '1':
                    $this->addController($moduleName, $modulePath);
                    break;
                case '2':
                    $this->createModuleDirectories($moduleName, $modulePath);
                    break;
                case '3':
                    $this->createBackendMenu($moduleName, $modulePath);
                    break;
                case '4':
                    $this->handleExtends($moduleName, $modulePath);
                    break;
                case '5':
                    $this->initThemeFiles($moduleName, $modulePath);
                    break;
                case '6':
                    $this->showConfig();
                    break;
                case '7':
                    $this->continueModuleCreation($moduleName);
                    break;
                case '8':
                    // 询问要翻译哪些语言
                    $this->printer->note(__('请输入要收集和翻译的语言（多个语言用逗号分隔，例如：zh_Hans_CN,en_US,ja_JP），默认：zh_Hans_CN'));
                    $languagesInput = trim($this->system->input());
                    if (empty($languagesInput)) {
                        $languages = ['zh_Hans_CN'];
                    } else {
                        $languages = array_map('trim', explode(',', $languagesInput));
                        $languages = array_filter($languages); // 移除空值
                        if (empty($languages)) {
                            $languages = ['zh_Hans_CN'];
                        }
                    }
                    $this->extractAndTranslateI18n($moduleName, $languages);
                    break;
                case '9':
                    $this->checkModuleIntegrity($moduleName, $modulePath);
                    break;
                case '10':
                    $this->installModule($moduleName, $modulePath);
                    break;
                case '11':
                    $this->reinstallModule($moduleName, $modulePath);
                    break;
                case '12':
                    $this->uninstallModule($moduleName, $modulePath);
                    break;
                case '13':
                    $this->rollbackModuleUninstall($moduleName, $modulePath);
                    break;
                case '14':
                    if (!$moduleGenerated) {
                        $controllers = $this->moduleConfig['controllers'] ?? [];
                        $this->generateModule($moduleName, $controllers);
                        $this->saveConfig();
                        $moduleGenerated = true; // 更新状态
                    } else {
                        $this->printer->warning(__('模块已生成，无需重复生成'));
                    }
                    break;
                case '0':
                    $this->printer->success(__('退出操作'));
                    return;
                default:
                    $this->printer->warning(__('无效选项，请重新选择'));
            }
        }
    }

    /**
     * 添加控制器（会立即保存配置）
     */
    private function addController(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 添加控制器 ==='));
        $controllers = $this->askForControllers($moduleName);
        
        $namespace = str_replace('_', '\\', $moduleName);
        foreach ($controllers as $controller) {
            $this->createController($modulePath, $namespace, $controller);
        }

        // 配置已在 askForControllers 中保存，这里不需要再次保存
    }

    /**
     * 处理继承
     */
    private function handleExtends(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 模块继承 ==='));
        
        // 扫描有规约文件的模块
        $allModules = $this->scanModulesWithConvention();
        
        if (empty($allModules)) {
            $this->printer->warning(__('未找到有规约文件的模块'));
            return;
        }

        // 询问是否搜索
        $this->printer->note(__('请输入要继承的模块名称（例如：Weline_Demo，支持模糊搜索，直接回车显示所有模块）'));
        $searchInput = trim($this->system->input());
        
        $modules = $allModules;
        if (!empty($searchInput)) {
            // 使用搜索功能过滤模块
            $modules = $this->filterModules($allModules, $searchInput);
            
            if (empty($modules)) {
                $this->printer->warning(__('未找到匹配的模块'));
                $this->printer->note(__('是否显示所有模块？(y/n，默认：y)'));
                $showAll = trim(strtolower($this->system->input()));
                if ($showAll !== 'n' && $showAll !== 'no') {
                    $modules = $allModules;
                } else {
                    return;
                }
            }
        }

        $this->printer->note(__('找到以下模块：'));
        $index = 1;
        foreach ($modules as $module) {
            // 将绝对路径转换为相对路径（相对于项目根目录）
            $relativePath = str_replace(BP, '', $module['path']);
            $relativePath = ltrim(str_replace('\\', DS, $relativePath), DS);
            
            // 美化显示：模块名加粗，路径根据类型着色
            $displayModuleName = $this->colorizeBold($module['name']);
            
            // 判断路径类型并着色
            if (strpos($relativePath, 'app' . DS . 'code') === 0) {
                // app\code 目录用蓝色
                $coloredPath = $this->colorizeText($relativePath, 'Blue');
            } elseif (strpos($relativePath, 'vendor') === 0) {
                // vendor 目录用紫色
                $coloredPath = $this->colorizeText($relativePath, 'Purple');
            } else {
                $coloredPath = $relativePath;
            }
            
            $this->printer->printing("  {$index}. {$displayModuleName} ({$coloredPath})\n");
            $index++;
        }

        $this->printer->note(__('请选择要继承的模块（输入序号）'));
        $choice = (int)trim($this->system->input());

        if ($choice < 1 || $choice > count($modules)) {
            $this->printer->error(__('无效选择'));
            return;
        }

        $selectedModule = $modules[$choice - 1];
        $this->createExtendsFiles($moduleName, $modulePath, $selectedModule);
    }

    /**
     * 扫描有规约文件的模块
     * 扫描所有有 extends.php 文件的模块
     */
    private function scanModulesWithConvention(): array
    {
        $modules = [];
        
        // 方式1：尝试使用 ExtendsData 获取有扩展定义的模块
        if (class_exists('Weline\Framework\Extends\ExtendsData')) {
            try {
                $modulesWithExtends = \Weline\Framework\Extends\ExtendsData::getModulesWithExtends();
                if (!empty($modulesWithExtends)) {
                    // 获取模块路径
                    $env = \Weline\Framework\App\Env::getInstance();
                    $moduleList = $env->getModuleList();
                    
                    foreach ($modulesWithExtends as $moduleName) {
                        if (isset($moduleList[$moduleName])) {
                            $modulePath = $moduleList[$moduleName]['base_path'] ?? '';
                            $extendsFile = $modulePath . DS . 'extends.php';
                            
                            if (!empty($modulePath) && file_exists($extendsFile)) {
                                $modules[] = [
                                    'name' => $moduleName,
                                    'path' => $modulePath,
                                    'convention_file' => $extendsFile
                                ];
                            }
                        }
                    }
                    
                    if (!empty($modules)) {
                        return $modules;
                    }
                }
            } catch (\Exception $e) {
                // 如果 ExtendsData 不可用，继续使用文件扫描方式
            }
        }
        
        // 方式2：直接扫描 app/code 下的模块，查找 extends.php 文件
        $appCodePath = APP_CODE_PATH;
        if (!is_dir($appCodePath)) {
            return $modules;
        }
        
        try {
            $dirIterator = new \RecursiveDirectoryIterator($appCodePath, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIterator = new \RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) {
                // 只扫描到模块目录级别（Vendor/ModuleName）
                $depth = $iterator->getDepth();
                return $depth <= 1;
            });
            $iterator = new \RecursiveIteratorIterator($filterIterator, \RecursiveIteratorIterator::SELF_FIRST);
            
            foreach ($iterator as $file) {
                if ($file->isDir() && $iterator->getDepth() === 1) {
                    // 这是模块目录
                    $modulePath = $file->getPathname();
                    $pathParts = explode(DS, str_replace($appCodePath, '', $modulePath));
                    $pathParts = array_filter($pathParts);
                    $pathParts = array_values($pathParts);
                    
                    if (count($pathParts) === 2) {
                        $moduleName = $pathParts[0] . '_' . $pathParts[1];
                        $extendsFile = $modulePath . DS . 'extends.php';
                        
                        if (file_exists($extendsFile)) {
                            $modules[] = [
                                'name' => $moduleName,
                                'path' => $modulePath,
                                'convention_file' => $extendsFile
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('扫描模块时出错：%{1}', [$e->getMessage()]));
        }

        return $modules;
    }

    /**
     * 创建继承文件
     */
    private function createExtendsFiles(string $moduleName, string $modulePath, array $targetModule): void
    {
        $this->printer->note(__('正在创建继承文件...'));
        
        // 创建 extends 目录结构
        $extendsPath = $modulePath . DS . 'extends' . DS . str_replace('_', DS, $targetModule['name']);
        if (!is_dir($extendsPath)) {
            mkdir($extendsPath, 0755, true);
        }

        // 读取规约文件内容
        $conventionContent = file_get_contents($targetModule['convention_file']);
        
        // 创建继承说明文件
        $readmeFile = $extendsPath . DS . 'README.md';
        if (!$this->checkFileExists($readmeFile, 'README.md')) {
            return;
        }

        // 在 heredoc 外部计算创建时间，避免语法错误
        $createdAt = !empty($this->moduleConfig['created_at']) ? $this->moduleConfig['created_at'] : date('Y-m-d H:i:s');

        $readmeContent = <<<MD
# 模块继承说明

本目录包含对模块 {$targetModule['name']} 的继承文件。

## 继承信息

- **目标模块**: {$targetModule['name']}
- **规约文件**: {$targetModule['convention_file']}
- **创建时间**: {$createdAt}

## 使用方法

请参考 Weline Framework 的模块继承文档。
MD;

        file_put_contents($readmeFile, $readmeContent);
        $this->printer->success(__('继承文件已创建：%{1}', [$extendsPath]));

        // 更新配置
        if (!isset($this->moduleConfig['extends'])) {
            $this->moduleConfig['extends'] = [];
        }
        $this->moduleConfig['extends'][] = [
            'target_module' => $targetModule['name'],
            'path' => $extendsPath,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $this->saveConfig();
    }

    /**
     * 初始化主题文件
     */
    private function initThemeFiles(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 初始化主题文件 ==='));
        
        // 扫描可用主题
        $themes = $this->scanThemes();
        
        if (empty($themes)) {
            $this->printer->warning(__('未找到可用主题'));
            return;
        }

        $this->printer->note(__('找到以下主题：'));
        $index = 1;
        foreach ($themes as $theme) {
            $this->printer->printing("  {$index}. {$theme}\n");
            $index++;
        }

        $this->printer->note(__('请选择要使用的主题（输入序号）'));
        $choice = (int)trim($this->system->input());

        if ($choice < 1 || $choice > count($themes)) {
            $this->printer->error(__('无效选择'));
            return;
        }

        $selectedTheme = $themes[$choice - 1];
        $this->copyThemeFiles($modulePath, $selectedTheme);
    }

    /**
     * 扫描主题
     */
    private function scanThemes(): array
    {
        $themes = [];
        $themePath = BP . 'app' . DS . 'design' . DS . 'frontend';
        
        if (is_dir($themePath)) {
            $themeDirs = glob($themePath . DS . '*', GLOB_ONLYDIR);
            foreach ($themeDirs as $themeDir) {
                $themes[] = basename($themeDir);
            }
        }

        return $themes;
    }

    /**
     * 复制主题文件
     */
    private function copyThemeFiles(string $modulePath, string $themeName): void
    {
        $themePath = BP . 'app' . DS . 'design' . DS . 'frontend' . DS . $themeName;
        $targetPath = $modulePath . DS . 'view' . DS . 'templates';
        
        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        // 这里可以实现具体的文件复制逻辑
        $this->printer->note(__('主题文件初始化功能待实现'));
        $this->printer->note(__('主题路径：%{1}', [$themePath]));
        $this->printer->note(__('目标路径：%{1}', [$targetPath]));
    }

    /**
     * 显示配置
     */
    private function showConfig(): void
    {
        $this->printer->printing("\n");
        $this->printer->note(__('=== 模块配置 ==='));
        $this->printer->printing(json_encode($this->moduleConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->printer->printing("\n");
    }

    /**
     * 显示模块配置摘要
     */
    private function showModuleConfigSummary(): void
    {
        $this->printer->printing("\n");
        $this->printer->note(__('=== 已配置内容摘要 ==='));
        
        $moduleName = $this->moduleConfig['module_name'] ?? '';
        if ($moduleName) {
            $this->printer->printing("模块名称: {$moduleName}\n");
        }
        
        $version = $this->moduleConfig['version'] ?? '';
        if ($version) {
            $this->printer->printing("版本: {$version}\n");
        }
        
        $description = $this->moduleConfig['description'] ?? '';
        if ($description) {
            $this->printer->printing("描述: {$description}\n");
        }
        
        $router = $this->moduleConfig['router'] ?? '';
        if ($router) {
            $this->printer->printing("路由前缀: {$router}\n");
        }
        
        $controllers = $this->moduleConfig['controllers'] ?? [];
        if (!empty($controllers)) {
            $this->printer->printing("控制器数量: " . count($controllers) . "\n");
            foreach ($controllers as $index => $controller) {
                $name = $controller['name'] ?? '未知';
                $type = $controller['type'] ?? '未知';
                $this->printer->printing("  - {$name} ({$type})\n");
            }
        }
        
        $events = $this->moduleConfig['events'] ?? [];
        if (!empty($events)) {
            $this->printer->printing("事件数量: " . count($events) . "\n");
            foreach ($events as $event) {
                $name = $event['name'] ?? '未知';
                $isCustom = $event['is_custom'] ?? false;
                $customText = $isCustom ? '（自定义）' : '（现有）';
                $this->printer->printing("  - {$name}{$customText}\n");
            }
        }
        
        $plugins = $this->moduleConfig['plugins'] ?? [];
        if (!empty($plugins)) {
            $this->printer->printing("插件数量: " . count($plugins) . "\n");
        }
        
        $models = $this->moduleConfig['models'] ?? [];
        if (!empty($models)) {
            $this->printer->printing("模型数量: " . count($models) . "\n");
        }
        
        $services = $this->moduleConfig['services'] ?? [];
        if (!empty($services)) {
            $this->printer->printing("服务数量: " . count($services) . "\n");
        }
        
        $extends = $this->moduleConfig['extends'] ?? [];
        if (!empty($extends)) {
            $this->printer->printing("继承模块数量: " . count($extends) . "\n");
        }
        
        $this->printer->printing("\n");
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '快速创建模块模组。支持交互式创建、控制器生成、高级定制等功能。';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:create',
            '快速创建模块模组，支持交互式向导和二次操作',
            [
                '-m, --module=<模块名>' => '指定模块名称（格式：Vendor_ModuleName）',
                '-c, --check' => '检测指定模块的完整性',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '交互式创建模块' => 'php bin/w module:create',
                '指定模块名创建' => 'php bin/w module:create -m Weline_Demo',
                '进入二次操作模式' => 'php bin/w module:create -m Weline_Demo',
            ],
            'php bin/w module:create [-m|--module=<模块名>]'
        );
    }

    /**
     * 创建模块目录结构
     */
    private function createModuleDirectories(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 创建模块目录结构 ==='));
        
        // 确保模块目录存在
        if (!is_dir($modulePath)) {
            mkdir($modulePath, 0755, true);
        }

        // 定义需要创建的目录
        $directories = [
            'Observer' => '观察者目录',
            'Plugin' => '插件目录',
            'Service' => '服务目录',
            'Model' => '模型目录',
            'Setup' => '安装脚本目录',
            'view/templates/Frontend' => '前端视图模板目录',
            'view/templates/Backend' => '后端视图模板目录',
            'view/blocks' => '视图块目录',
            'i18n' => '国际化目录',
            'etc/backend' => '后端配置目录',
            'etc/xsd' => 'XSD架构目录',
        ];

        $this->printer->note(__('请选择要创建的目录（多个用逗号分隔，例如：1,2,3，或输入 all 创建所有目录）：'));
        $index = 1;
        foreach ($directories as $dir => $desc) {
            $this->printer->printing("  {$index}. {$dir} - {$desc}\n");
            $index++;
        }
        $this->printer->printing("  all. 创建所有目录\n");

        $input = trim($this->system->input());
        $selected = [];

        if (strtolower($input) === 'all') {
            $selected = array_keys($directories);
        } else {
            $choices = array_map('trim', explode(',', $input));
            $dirKeys = array_keys($directories);
            foreach ($choices as $choice) {
                $idx = intval($choice) - 1;
                if ($idx >= 0 && $idx < count($dirKeys)) {
                    $selected[] = $dirKeys[$idx];
                }
            }
        }

        if (empty($selected)) {
            $this->printer->warning(__('未选择任何目录'));
            return;
        }

        $created = [];
        foreach ($selected as $dir) {
            $fullPath = $modulePath . DS . str_replace('/', DS, $dir);
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
                $created[] = $dir;
                $this->printer->success(__('已创建目录：%{1}', [$dir]));
            } else {
                $this->printer->note(__('目录已存在：%{1}', [$dir]));
            }
        }

        if (!empty($created)) {
            $this->printer->success(__('成功创建 %{1} 个目录', [count($created)]));
        }
    }

    /**
     * 创建后台菜单
     */
    private function createBackendMenu(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 后台菜单管理 ==='));

        // 确保 etc/backend 目录存在
        $etcBackendPath = $modulePath . DS . 'etc' . DS . 'backend';
        if (!is_dir($etcBackendPath)) {
            mkdir($etcBackendPath, 0755, true);
        }

        $menuFile = $etcBackendPath . DS . 'menu.xml';
        
        // 读取现有菜单
        $existingMenus = [];
        if (file_exists($menuFile)) {
            $existingMenus = $this->parseMenuXml($menuFile);
        }

            while (true) {
            $this->printer->printing("\n");
            $this->printer->note(__('请选择操作：'));
            $this->printer->note(__('1. 新建菜单'));
            if (!empty($existingMenus)) {
                $this->printer->note(__('2. 修改菜单'));
                $this->printer->note(__('3. 删除菜单'));
                $this->printer->note(__('4. 移动菜单'));
                $this->printer->note(__('5. 查看当前菜单'));
                $this->printer->note(__('6. 刷新菜单收集（使菜单生效）'));
            } else {
                $this->printer->note(__('2. 刷新菜单收集（使菜单生效）'));
            }
            $this->printer->note(__('0. 退出'));
            
            $choice = trim($this->system->input());
            
            switch ($choice) {
                case '1':
                    $this->addMenu($moduleName, $modulePath, $menuFile, $existingMenus);
                    // 重新读取菜单
                    if (file_exists($menuFile)) {
                        $existingMenus = $this->parseMenuXml($menuFile);
                    }
                    // 新建菜单后自动刷新菜单收集
                    $this->printer->note(__('正在刷新菜单收集...'));
                    $this->refreshMenuCollection();
                    break;
                case '2':
                    if (!empty($existingMenus)) {
                        $this->editMenu($moduleName, $modulePath, $menuFile, $existingMenus);
                        // 重新读取菜单
                        if (file_exists($menuFile)) {
                            $existingMenus = $this->parseMenuXml($menuFile);
                        }
                    } else {
                        // 没有菜单时，选项2是刷新菜单收集
                        $this->refreshMenuCollection();
                    }
                    break;
                case '3':
                    if (!empty($existingMenus)) {
                        $this->deleteMenu($moduleName, $modulePath, $menuFile, $existingMenus);
                        // 重新读取菜单
                        if (file_exists($menuFile)) {
                            $existingMenus = $this->parseMenuXml($menuFile);
                        }
                    } else {
                        $this->printer->warning(__('无效选项，请重新选择'));
                    }
                    break;
                case '4':
                    if (!empty($existingMenus)) {
                        $this->moveMenu($moduleName, $modulePath, $menuFile, $existingMenus);
                        // 重新读取菜单
                        if (file_exists($menuFile)) {
                            $existingMenus = $this->parseMenuXml($menuFile);
                        }
                    } else {
                        $this->printer->warning(__('无效选项，请重新选择'));
                    }
                    break;
                case '5':
                    if (!empty($existingMenus)) {
                        $this->showMenus($existingMenus);
                    } else {
                        $this->printer->warning(__('无效选项，请重新选择'));
                    }
                    break;
                case '6':
                    if (!empty($existingMenus)) {
                        $this->refreshMenuCollection();
                    } else {
                        $this->printer->warning(__('无效选项，请重新选择'));
                    }
                    break;
                case '0':
                    return;
                default:
                    $this->printer->warning(__('无效选项，请重新选择'));
            }
        }
    }
    
    /**
     * 刷新菜单收集（使菜单生效）
     */
    private function refreshMenuCollection(): void
    {
        try {
            $this->printer->note(__('步骤 1/3：收集菜单...'));
            $command = 'php ' . BP . 'bin' . DS . 'w backend:menu:collect';
            $output = [];
            $returnVar = 0;
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->printer->success(__('菜单收集完成！'));
                if (!empty($output)) {
                    // 显示关键输出信息
                    foreach ($output as $line) {
                        if (stripos($line, 'error') !== false || stripos($line, 'success') !== false || stripos($line, '完成') !== false) {
                            $this->printer->printing($line . "\n");
                        }
                    }
                }
            } else {
                $this->printer->warning(__('菜单收集可能未完全完成'));
                if (!empty($output)) {
                    $this->printer->printing(implode("\n", $output) . "\n");
                }
            }
            
            // 清除缓存
            $this->printer->note(__('步骤 2/3：清除缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            
            if ($cacheReturnVar === 0) {
                $this->printer->success(__('缓存清除完成！'));
            }
            
            $this->printer->note(__('步骤 3/3：菜单已刷新并生效！'));
        } catch (\Exception $e) {
            $this->printer->error(__('刷新菜单收集时出错：%{1}', [$e->getMessage()]));
            $this->printer->note(__('请手动运行以下命令刷新菜单：'));
            $this->printer->printing("  php bin/w backend:menu:collect\n");
        }
    }

    /**
     * 添加菜单
     */
    private function addMenu(string $moduleName, string $modulePath, string $menuFile, array $existingMenus): void
    {
        $this->printer->note(__('=== 新建菜单 ==='));
        
        // 获取模块路由前缀
        $router = $this->moduleConfig['router'] ?? strtolower(str_replace('_', '', $moduleName));
        
        // 1. 选择父级菜单
        $parentMenus = $this->scanParentMenus();
        $parent = $this->selectParentMenu($parentMenus);
        
        // 2. 输入菜单名称
        $defaultMenuName = strtolower(str_replace('_', '_', $moduleName));
        $this->printer->note(__('请输入菜单名称（唯一标识，例如：my_module_main，默认：%{1}）', [$defaultMenuName]));
        $menuNameInput = trim($this->system->input());
        $menuName = !empty($menuNameInput) ? $menuNameInput : $defaultMenuName;
        
        // 检查菜单名是否已存在
        foreach ($existingMenus as $menu) {
            if (($menu['name'] ?? '') === $menuName) {
                $this->printer->error(__('菜单名称已存在：%{1}', [$menuName]));
                return;
            }
        }
        
        // 3. 输入菜单标题
        $this->printer->note(__('请输入菜单标题（例如：我的模块）'));
        $title = trim($this->system->input());
        if (empty($title)) {
            $this->printer->warning(__('菜单标题不能为空'));
            return;
        }
        
        // 4. 选择控制器路由（从Acl提取）
        $this->printer->note(__('是否选择本模块的控制器路由？(y/n，默认：y，不选择则创建父级菜单)'));
        $selectRouteInput = trim(strtolower($this->system->input()));
        $selectRoute = !empty($selectRouteInput) ? $selectRouteInput : 'y';
        
        $action = '';
        $aclSource = '';
        $icon = '';
        
        if ($selectRoute === 'y' || $selectRoute === 'yes') {
            // 扫描本模块的Acl
            $aclRoutes = $this->scanModuleAclRoutes($modulePath, $moduleName);
            
            if (!empty($aclRoutes)) {
                $this->printer->note(__('找到以下控制器路由（从Acl提取）：'));
                $index = 1;
                foreach ($aclRoutes as $route) {
                    $sourceId = $route['source_id'] ?? '';
                    $sourceName = $route['source_name'] ?? '';
                    $routePath = $route['route'] ?? '';
                    $routeIcon = $route['icon'] ?? '';
                    
                    // 将路由路径中的模块路由前缀替换为 *
                    $displayRoutePath = $routePath;
                    if (!empty($router) && strpos($routePath, $router . '/') === 0) {
                        $displayRoutePath = '*' . substr($routePath, strlen($router));
                    }
                    
                    // 只有当 icon 不为空且不是空字符串时才显示
                    $iconText = (!empty($routeIcon) && trim($routeIcon) !== '') ? " [图标: {$routeIcon}]" : '';
                    $this->printer->printing("  {$index}. {$sourceName} ({$sourceId}) - {$displayRoutePath}{$iconText}\n");
                    $index++;
                }
                
                $this->printer->note(__('请选择路由（输入序号，或输入 0 跳过）'));
                $routeChoice = (int)trim($this->system->input());
                
                if ($routeChoice > 0 && $routeChoice <= count($aclRoutes)) {
                    $selectedRoute = $aclRoutes[$routeChoice - 1];
                    $action = $selectedRoute['route'] ?? '';
                    $aclSource = $selectedRoute['source_id'] ?? '';
                    $icon = $selectedRoute['icon'] ?? '';
                    
                    // 如果图标为空，询问是否输入
                    if (empty($icon)) {
                        $this->printer->note(__('请输入图标类名（例如：mdi mdi-home，留空则不设置）'));
                        $icon = trim($this->system->input());
                    }
                }
            } else {
                $this->printer->warning(__('未找到控制器路由'));
                $this->printer->printing("\n");
                $this->printer->note(__('重要提示：'));
                $this->printer->note(__('1. 后端控制器（Backend）必须配置Acl权限注解才能被菜单识别'));
                $this->printer->note(__('2. 后端API控制器（BackendApi）必须配置Acl权限注解'));
                $this->printer->note(__('3. 前端API控制器（FrontendApi）有两种模式：'));
                $this->printer->note(__('   - 公开接口：不需要Acl注解，不需要登录即可访问'));
                $this->printer->note(__('   - 需要登录：不需要Acl注解，但需要用户登录后才能访问'));
                $this->printer->printing("\n");
                $this->printer->note(__('请手动输入菜单路由（例如：backend/index/index，留空则不设置）'));
                $this->printer->note(__('提示：如果控制器未配置Acl，请先为控制器添加Acl注解，然后重新运行此命令'));
                $router = $this->moduleConfig['router'] ?? strtolower(str_replace('_', '', $moduleName));
                $actionInput = trim($this->system->input());
                if (!empty($actionInput)) {
                    $action = $router . '/' . ltrim($actionInput, '/');
                }
            }
        } else {
            // 不选择路由，创建父级菜单
            $this->printer->note(__('将创建父级菜单（其他菜单可以挂载在此菜单下）'));
        }
        
        // 5. 输入图标（如果还没有）
        if (empty($icon)) {
            $this->printer->note(__('请输入图标类名（例如：mdi mdi-home，留空则不设置）'));
            $icon = trim($this->system->input());
        }
        
        // 6. 输入排序
        $this->printer->note(__('请输入排序数字（默认：100）'));
        $orderInput = trim($this->system->input());
        $order = !empty($orderInput) ? intval($orderInput) : 100;
        
        // 生成菜单项
        $moduleSource = str_replace('_', '_', $moduleName);
        $source = $moduleSource . '::' . $menuName;
        // 顶级菜单的 parent 是空字符串 parent=""
        $parentAttr = !empty($parent) ? " parent=\"{$parent}\"" : ' parent=""';
        $iconAttr = !empty($icon) ? " icon=\"{$icon}\"" : '';
        $actionAttr = !empty($action) ? " action=\"{$action}\"" : '';
        
        $menuItem = "    <add source=\"{$source}\" name=\"{$menuName}\" title=\"{$title}\"{$actionAttr}{$parentAttr}{$iconAttr} order=\"{$order}\"/>";
        
        // 读取现有内容
        $existingContent = '';
        if (file_exists($menuFile)) {
            $existingContent = file_get_contents($menuFile);
        }
        
        // 如果文件不存在，创建新文件
        if (empty($existingContent)) {
            $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<menus xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
       xs:noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"
       xs:schemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd">
{$menuItem}
</menus>
XML;
            file_put_contents($menuFile, $content);
            $this->printer->success(__('已创建菜单文件：%{1}', [$menuFile]));
        } else {
            // 在 </menus> 前插入新菜单项
            $newContent = str_replace('</menus>', $menuItem . "\n</menus>", $existingContent);
            file_put_contents($menuFile, $newContent);
            $this->printer->success(__('已添加菜单项到：%{1}', [$menuFile]));
        }
        
        $this->printer->note(__('菜单创建完成！请运行 php bin/w module:upgrade 刷新菜单'));
    }

    /**
     * 修改菜单
     */
    private function editMenu(string $moduleName, string $modulePath, string $menuFile, array $existingMenus): void
    {
        $this->printer->note(__('=== 修改菜单 ==='));
        
        // 显示现有菜单
        $this->showMenus($existingMenus);
        
        $this->printer->note(__('请选择要修改的菜单（输入序号）'));
        $choice = (int)trim($this->system->input());
        
        if ($choice < 1 || $choice > count($existingMenus)) {
            $this->printer->error(__('无效选择'));
            return;
        }
        
        $menu = $existingMenus[$choice - 1];
        $menuSource = $menu['source'] ?? '';
        
        // 读取文件内容
        $content = file_get_contents($menuFile);
        
        // 询问要修改的字段
        $this->printer->note(__('请选择要修改的字段：'));
        $this->printer->note(__('1. 菜单标题'));
        $this->printer->note(__('2. 菜单路由'));
        $this->printer->note(__('3. 父级菜单'));
        $this->printer->note(__('4. 图标'));
        $this->printer->note(__('5. 排序'));
        $this->printer->note(__('0. 取消'));
        
        $fieldChoice = trim($this->system->input());
        
        $newValue = '';
        $fieldName = '';
        
        switch ($fieldChoice) {
            case '1':
                $fieldName = 'title';
                $this->printer->note(__('当前标题：%{1}', [$menu['title'] ?? '']));
                $this->printer->note(__('请输入新标题'));
                $newValue = trim($this->system->input());
                break;
            case '2':
                $fieldName = 'action';
                $this->printer->note(__('当前路由：%{1}', [$menu['action'] ?? '']));
                $this->printer->note(__('请输入新路由（留空则删除路由）'));
                $newValue = trim($this->system->input());
                break;
            case '3':
                $fieldName = 'parent';
                $this->printer->note(__('当前父级：%{1}', [$menu['parent'] ?? '无']));
                $parentMenus = $this->scanParentMenus();
                $newValue = $this->selectParentMenu($parentMenus, true);
                break;
            case '4':
                $fieldName = 'icon';
                $this->printer->note(__('当前图标：%{1}', [$menu['icon'] ?? '']));
                $this->printer->note(__('请输入新图标（留空则删除图标）'));
                $newValue = trim($this->system->input());
                break;
            case '5':
                $fieldName = 'order';
                $this->printer->note(__('当前排序：%{1}', [$menu['order'] ?? '100']));
                $this->printer->note(__('请输入新排序'));
                $newValue = trim($this->system->input());
                break;
            case '0':
                return;
            default:
                $this->printer->warning(__('无效选择'));
                return;
        }
        
        // 更新XML
        $pattern = '/(<add[^>]*source="' . preg_quote($menuSource, '/') . '"[^>]*>)/';
        
        if (preg_match($pattern, $content, $matches)) {
            $oldLine = $matches[1];
            $newLine = $oldLine;
            
            // 更新对应字段
            if ($fieldName === 'title') {
                $newLine = preg_replace('/title="[^"]*"/', 'title="' . htmlspecialchars($newValue, ENT_QUOTES) . '"', $newLine);
            } elseif ($fieldName === 'action') {
                if (empty($newValue)) {
                    $newLine = preg_replace('/\s+action="[^"]*"/', '', $newLine);
                } else {
                    $router = $this->moduleConfig['router'] ?? strtolower(str_replace('_', '', $moduleName));
                    $actionValue = $router . '/' . ltrim($newValue, '/');
                    if (preg_match('/action="[^"]*"/', $newLine)) {
                        $newLine = preg_replace('/action="[^"]*"/', 'action="' . htmlspecialchars($actionValue, ENT_QUOTES) . '"', $newLine);
                    } else {
                        $newLine = rtrim($newLine, '/>') . ' action="' . htmlspecialchars($actionValue, ENT_QUOTES) . '"/>';
                    }
                }
            } elseif ($fieldName === 'parent') {
                if (empty($newValue)) {
                    $newLine = preg_replace('/\s+parent="[^"]*"/', '', $newLine);
                } else {
                    if (preg_match('/parent="[^"]*"/', $newLine)) {
                        $newLine = preg_replace('/parent="[^"]*"/', 'parent="' . htmlspecialchars($newValue, ENT_QUOTES) . '"', $newLine);
                    } else {
                        $newLine = rtrim($newLine, '/>') . ' parent="' . htmlspecialchars($newValue, ENT_QUOTES) . '"/>';
                    }
                }
            } elseif ($fieldName === 'icon') {
                if (empty($newValue)) {
                    $newLine = preg_replace('/\s+icon="[^"]*"/', '', $newLine);
                } else {
                    if (preg_match('/icon="[^"]*"/', $newLine)) {
                        $newLine = preg_replace('/icon="[^"]*"/', 'icon="' . htmlspecialchars($newValue, ENT_QUOTES) . '"', $newLine);
                    } else {
                        $newLine = rtrim($newLine, '/>') . ' icon="' . htmlspecialchars($newValue, ENT_QUOTES) . '"/>';
                    }
                }
            } elseif ($fieldName === 'order') {
                if (preg_match('/order="[^"]*"/', $newLine)) {
                    $newLine = preg_replace('/order="[^"]*"/', 'order="' . htmlspecialchars($newValue, ENT_QUOTES) . '"', $newLine);
                } else {
                    $newLine = rtrim($newLine, '/>') . ' order="' . htmlspecialchars($newValue, ENT_QUOTES) . '"/>';
                }
            }
            
            $newContent = str_replace($oldLine, $newLine, $content);
            file_put_contents($menuFile, $newContent);
            $this->printer->success(__('菜单已更新'));
        } else {
            $this->printer->error(__('未找到要修改的菜单项'));
        }
    }

    /**
     * 删除菜单
     */
    private function deleteMenu(string $moduleName, string $modulePath, string $menuFile, array $existingMenus): void
    {
        $this->printer->note(__('=== 删除菜单 ==='));
        
        // 显示现有菜单
        $this->showMenus($existingMenus);
        
        $this->printer->note(__('请选择要删除的菜单（输入序号）'));
        $choice = (int)trim($this->system->input());
        
        if ($choice < 1 || $choice > count($existingMenus)) {
            $this->printer->error(__('无效选择'));
            return;
        }
        
        $menu = $existingMenus[$choice - 1];
        $menuTitle = $menu['title'] ?? '';
        
        $this->printer->warning(__('确定要删除菜单：%{1}？(y/n，默认：n)', [$menuTitle]));
        $confirm = trim(strtolower($this->system->input()));
        
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printer->note(__('已取消删除'));
            return;
        }
        
        // 读取文件内容
        $content = file_get_contents($menuFile);
        $menuSource = $menu['source'] ?? '';
        
        // 删除对应的菜单行
        $pattern = '/\s*<add[^>]*source="' . preg_quote($menuSource, '/') . '"[^>]*\/>\s*\n?/';
        $newContent = preg_replace($pattern, '', $content);
        
        file_put_contents($menuFile, $newContent);
        $this->printer->success(__('菜单已删除'));
    }

    /**
     * 移动菜单
     */
    private function moveMenu(string $moduleName, string $modulePath, string $menuFile, array $existingMenus): void
    {
        $this->printer->note(__('=== 移动菜单 ==='));
        
        // 显示现有菜单
        $this->showMenus($existingMenus);
        
        // 选择要移动的菜单
        $this->printer->note(__('请选择要移动的菜单（输入序号）'));
        $sourceChoice = (int)trim($this->system->input());
        
        if ($sourceChoice < 1 || $sourceChoice > count($existingMenus)) {
            $this->printer->error(__('无效选择'));
            return;
        }
        
        $sourceMenu = $existingMenus[$sourceChoice - 1];
        $sourceMenuTitle = $sourceMenu['title'] ?? '';
        $sourceMenuSource = $sourceMenu['source'] ?? '';
        
        $this->printer->note(__('已选择菜单：%{1}', [$sourceMenuTitle]));
        
        // 获取所有可用的父菜单（包括本模块的所有菜单和其他模块的顶级菜单）
        $allParentMenus = $this->getAllAvailableParentMenus($existingMenus, $sourceMenuSource);
        
        // 显示可用的父菜单
        $this->printer->note(__('请选择目标父菜单（输入序号，输入 0 则移动到顶级）'));
        $this->printer->printing("  0. 顶级菜单（parent=\"\"）\n");
        $index = 1;
        foreach ($allParentMenus as $parentMenu) {
            $parentTitle = $parentMenu['title'] ?? '';
            $parentSource = $parentMenu['source'] ?? '';
            $this->printer->printing("  {$index}. {$parentTitle} ({$parentSource})\n");
            $index++;
        }
        
        $targetChoice = (int)trim($this->system->input());
        
        $targetParent = '';
        if ($targetChoice === 0) {
            // 移动到顶级
            $targetParent = '';
        } elseif ($targetChoice > 0 && $targetChoice <= count($allParentMenus)) {
            $targetParent = $allParentMenus[$targetChoice - 1]['source'] ?? '';
        } else {
            $this->printer->error(__('无效选择'));
            return;
        }
        
        // 检查是否试图将菜单移动到自己的子菜单下（防止循环）
        if (!empty($targetParent) && $this->wouldCreateCircularReference($existingMenus, $sourceMenuSource, $targetParent)) {
            $this->printer->error(__('不能将菜单移动到自己的子菜单下，这会造成循环引用'));
            return;
        }
        
        // 读取文件内容
        $content = file_get_contents($menuFile);
        $sourceMenuSourceEscaped = preg_quote($sourceMenuSource, '/');
        
        // 更新菜单的 parent 属性
        $pattern = '/(<add[^>]*source="' . $sourceMenuSourceEscaped . '"[^>]*)(parent="[^"]*")?([^>]*>)/';
        
        if (preg_match($pattern, $content, $matches)) {
            $beforeParent = $matches[1];
            $afterParent = $matches[3];
            
            // 构建新的菜单行
            if (empty($targetParent)) {
                // 移动到顶级，设置 parent=""
                if (isset($matches[2])) {
                    // 替换现有的 parent 属性
                    $newLine = $beforeParent . ' parent=""' . $afterParent;
                } else {
                    // 添加 parent="" 属性（在 > 之前插入）
                    $newLine = preg_replace('/(\s*)(\/?>)/', '$1parent=""$2', $beforeParent . $afterParent);
                }
            } else {
                // 移动到指定父菜单下
                if (isset($matches[2])) {
                    // 替换现有的 parent 属性
                    $newLine = $beforeParent . ' parent="' . htmlspecialchars($targetParent, ENT_QUOTES) . '"' . $afterParent;
                } else {
                    // 添加 parent 属性（在 > 之前插入）
                    $newLine = preg_replace('/(\s*)(\/?>)/', '$1parent="' . htmlspecialchars($targetParent, ENT_QUOTES) . '"$2', $beforeParent . $afterParent);
                }
            }
            
            $newContent = str_replace($matches[0], $newLine, $content);
            file_put_contents($menuFile, $newContent);
            
            $targetText = empty($targetParent) ? __('顶级菜单') : $targetParent;
            $this->printer->success(__('菜单 %{1} 已移动到 %{2}', [$sourceMenuTitle, $targetText]));
        } else {
            $this->printer->error(__('未找到要移动的菜单项'));
        }
    }

    /**
     * 获取所有可用的父菜单（包括本模块的所有菜单和其他模块的顶级菜单）
     */
    private function getAllAvailableParentMenus(array $existingMenus, string $excludeSource): array
    {
        $parentMenus = [];
        
        // 1. 添加本模块的所有菜单（排除要移动的菜单本身）
        foreach ($existingMenus as $menu) {
            $source = $menu['source'] ?? '';
            if ($source !== $excludeSource) {
                $parentMenus[] = [
                    'source' => $source,
                    'title' => $menu['title'] ?? '',
                    'name' => $menu['name'] ?? '',
                ];
            }
        }
        
        // 2. 添加其他模块的顶级菜单
        $otherParentMenus = $this->scanParentMenus();
        foreach ($otherParentMenus as $parentMenu) {
            $source = $parentMenu['source'] ?? '';
            // 避免重复
            $exists = false;
            foreach ($parentMenus as $pm) {
                if ($pm['source'] === $source) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $parentMenus[] = $parentMenu;
            }
        }
        
        return $parentMenus;
    }

    /**
     * 检查是否会造成循环引用
     */
    private function wouldCreateCircularReference(array $menus, string $sourceMenuSource, string $targetParentSource): bool
    {
        // 如果目标父菜单是要移动的菜单本身，则会造成循环
        if ($targetParentSource === $sourceMenuSource) {
            return true;
        }
        
        // 检查目标父菜单是否是源菜单的子菜单（递归检查）
        $visited = [];
        return $this->isDescendant($menus, $targetParentSource, $sourceMenuSource, $visited);
    }

    /**
     * 检查 targetSource 是否是 ancestorSource 的后代
     */
    private function isDescendant(array $menus, string $targetSource, string $ancestorSource, array &$visited): bool
    {
        if (in_array($targetSource, $visited)) {
            return false; // 避免无限循环
        }
        $visited[] = $targetSource;
        
        // 找到目标菜单
        $targetMenu = null;
        foreach ($menus as $menu) {
            if (($menu['source'] ?? '') === $targetSource) {
                $targetMenu = $menu;
                break;
            }
        }
        
        if (!$targetMenu) {
            return false;
        }
        
        $parent = $targetMenu['parent'] ?? '';
        
        // 如果父菜单是祖先菜单，则返回 true
        if ($parent === $ancestorSource) {
            return true;
        }
        
        // 如果父菜单为空，则不是后代
        if (empty($parent)) {
            return false;
        }
        
        // 递归检查父菜单
        return $this->isDescendant($menus, $parent, $ancestorSource, $visited);
    }

    /**
     * 显示菜单列表
     */
    private function showMenus(array $menus): void
    {
        $this->printer->note(__('当前菜单列表：'));
        $index = 1;
        foreach ($menus as $menu) {
            $title = $menu['title'] ?? '';
            $name = $menu['name'] ?? '';
            $source = $menu['source'] ?? '';
            $action = $menu['action'] ?? '';
            $parent = $menu['parent'] ?? '';
            $icon = $menu['icon'] ?? '';
            $order = $menu['order'] ?? '100';
            
            $this->printer->printing("  {$index}. {$title} ({$name})\n");
            $this->printer->printing("      Source: {$source}\n");
            if (!empty($action)) {
                $this->printer->printing("      路由: {$action}\n");
            }
            if (!empty($parent)) {
                $this->printer->printing("      父级: {$parent}\n");
            }
            if (!empty($icon)) {
                $this->printer->printing("      图标: {$icon}\n");
            }
            $this->printer->printing("      排序: {$order}\n");
            $index++;
        }
    }

    /**
     * 解析菜单XML
     */
    private function parseMenuXml(string $menuFile): array
    {
        $menus = [];
        
        if (!file_exists($menuFile)) {
            return $menus;
        }
        
        try {
            $xml = simplexml_load_file($menuFile);
            if ($xml === false) {
                return $menus;
            }
            
            foreach ($xml->add as $add) {
                $menu = [
                    'source' => (string)$add['source'],
                    'name' => (string)$add['name'],
                    'title' => (string)$add['title'],
                    'action' => (string)($add['action'] ?? ''),
                    'parent' => (string)($add['parent'] ?? ''),
                    'icon' => (string)($add['icon'] ?? ''),
                    'order' => (string)($add['order'] ?? '100'),
                ];
                $menus[] = $menu;
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('解析菜单XML时出错：%{1}', [$e->getMessage()]));
        }
        
        return $menus;
    }

    /**
     * 扫描所有菜单（从所有模块的menu.xml中提取所有菜单，构建菜单树）
     */
    private function scanAllMenus(): array
    {
        $allMenus = [];
        
        try {
            // 扫描所有模块的menu.xml
            $appCodePath = APP_CODE_PATH;
            if (!is_dir($appCodePath)) {
                return $allMenus;
            }
            
            $dirIterator = new \RecursiveDirectoryIterator($appCodePath, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIterator = new \RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) {
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($filterIterator);
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'menu.xml' && 
                    strpos($file->getPathname(), DS . 'etc' . DS . 'backend' . DS) !== false) {
                    
                    try {
                        $xml = simplexml_load_file($file->getPathname());
                        if ($xml === false) {
                            continue;
                        }
                        
                        foreach ($xml->add as $add) {
                            $source = (string)$add['source'];
                            $parent = (string)($add['parent'] ?? '');
                            
                            // 避免重复
                            if (!isset($allMenus[$source])) {
                                $allMenus[$source] = [
                                    'source' => $source,
                                    'title' => (string)$add['title'],
                                    'name' => (string)$add['name'],
                                    'parent' => $parent,
                                    'action' => (string)($add['action'] ?? ''),
                                    'icon' => (string)($add['icon'] ?? ''),
                                    'order' => (string)($add['order'] ?? '100'),
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        // 忽略解析错误
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('扫描菜单时出错：%{1}', [$e->getMessage()]));
        }
        
        return $allMenus;
    }
    
    /**
     * 构建菜单树结构
     */
    private function buildMenuTree(array $allMenus): array
    {
        $tree = [];
        $menuMap = [];
        
        // 先建立映射
        foreach ($allMenus as $menu) {
            $menuMap[$menu['source']] = $menu;
        }
        
        // 构建树结构
        foreach ($allMenus as $menu) {
            $parent = $menu['parent'];
            if (empty($parent)) {
                // 顶级菜单
                if (!isset($tree[$menu['source']])) {
                    $tree[$menu['source']] = [
                        'menu' => $menu,
                        'children' => []
                    ];
                }
            } else {
                // 子菜单
                if (isset($menuMap[$parent])) {
                    // 确保父菜单在树中
                    if (!isset($tree[$parent])) {
                        $tree[$parent] = [
                            'menu' => $menuMap[$parent],
                            'children' => []
                        ];
                    }
                    $tree[$parent]['children'][$menu['source']] = [
                        'menu' => $menu,
                        'children' => []
                    ];
                }
            }
        }
        
        // 递归构建子菜单树
        foreach ($tree as $source => &$node) {
            $this->buildChildrenTree($node, $allMenus);
        }
        
        // 对顶级菜单按照order字段排序
        uasort($tree, function($a, $b) {
            $orderA = (int)($a['menu']['order'] ?? 100);
            $orderB = (int)($b['menu']['order'] ?? 100);
            if ($orderA === $orderB) {
                // 如果order相同，按照title排序
                return strcmp($a['menu']['title'] ?? '', $b['menu']['title'] ?? '');
            }
            return $orderA <=> $orderB;
        });
        
        return $tree;
    }
    
    /**
     * 递归构建子菜单树
     */
    private function buildChildrenTree(array &$node, array $allMenus): void
    {
        foreach ($allMenus as $menu) {
            if ($menu['parent'] === $node['menu']['source']) {
                if (!isset($node['children'][$menu['source']])) {
                    $node['children'][$menu['source']] = [
                        'menu' => $menu,
                        'children' => []
                    ];
                    // 递归构建子菜单
                    $this->buildChildrenTree($node['children'][$menu['source']], $allMenus);
                }
            }
        }
        
        // 对子菜单按照order字段排序
        if (!empty($node['children'])) {
            uasort($node['children'], function($a, $b) {
                $orderA = (int)($a['menu']['order'] ?? 100);
                $orderB = (int)($b['menu']['order'] ?? 100);
                if ($orderA === $orderB) {
                    // 如果order相同，按照title排序
                    return strcmp($a['menu']['title'] ?? '', $b['menu']['title'] ?? '');
                }
                return $orderA <=> $orderB;
            });
        }
    }
    
    /**
     * 扫描父级菜单（从所有模块的menu.xml中提取parent为空的菜单）
     * @deprecated 使用 selectParentMenuWithTree 代替
     */
    private function scanParentMenus(): array
    {
        $allMenus = $this->scanAllMenus();
        $parentMenus = [];
        
        foreach ($allMenus as $menu) {
            if (empty($menu['parent'])) {
                $parentMenus[] = $menu;
            }
        }
        
        return $parentMenus;
    }

    /**
     * 选择父级菜单（支持多级展开）
     */
    private function selectParentMenu(array $parentMenus, bool $allowEmpty = true): string
    {
        // 使用新的树形选择方法
        return $this->selectParentMenuWithTree($allowEmpty);
    }
    
    /**
     * 使用树形结构选择父级菜单（支持多级展开）
     * 支持输入 .1, .2 这样的格式展开当前菜单列表的子菜单
     */
    private function selectParentMenuWithTree(bool $allowEmpty = true, array $currentPath = []): string
    {
        $allMenus = $this->scanAllMenus();
        $tree = $this->buildMenuTree($allMenus);
        
        if (empty($tree)) {
            if ($allowEmpty) {
                $this->printer->note(__('未找到父级菜单，将创建顶级菜单'));
                return '';
            }
            return '';
        }
        
        // 如果当前路径为空，显示顶级菜单
        if (empty($currentPath)) {
            // 只显示顶级菜单（parent为空的菜单）
            $topLevelMenus = [];
            foreach ($tree as $source => $node) {
                $menu = $node['menu'];
                // 确保是顶级菜单（parent为空）
                if (empty($menu['parent'])) {
                    $topLevelMenus[$source] = $node;
                }
            }
            
            if (empty($topLevelMenus)) {
                if ($allowEmpty) {
                    $this->printer->note(__('未找到顶级菜单，将创建顶级菜单'));
                    return '';
                }
                return '';
            }
            
            // 按照order字段排序顶级菜单
            uasort($topLevelMenus, function($a, $b) {
                $orderA = (int)($a['menu']['order'] ?? 100);
                $orderB = (int)($b['menu']['order'] ?? 100);
                if ($orderA === $orderB) {
                    // 如果order相同，按照title排序
                    return strcmp($a['menu']['title'] ?? '', $b['menu']['title'] ?? '');
                }
                return $orderA <=> $orderB;
            });
            
            $this->printer->note(__('请选择父级菜单（留空则为顶级菜单，输入数字选择菜单，输入.1/.2等展开子菜单）：'));
            $index = 1;
            $menuList = [];
            foreach ($topLevelMenus as $source => $node) {
                $menu = $node['menu'];
                $title = $menu['title'] ?? '';
                $hasChildren = !empty($node['children']);
                $indicator = $hasChildren ? ' ▶' : '';
                $this->printer->printing("  {$index}. {$title} ({$menu['source']}){$indicator}\n");
                $menuList[] = ['source' => $source, 'node' => $node];
                $index++;
            }
            
            $choice = trim($this->system->input());
            
            if (empty($choice)) {
                return '';
            }
            
            // 在顶级菜单层级，输入0表示已经在顶级，忽略并提示
            if ($choice === '0') {
                $this->printer->warning(__('已经在顶级菜单，请输入其他选项或留空选择顶级菜单'));
                // 重新显示顶级菜单
                return $this->selectParentMenuWithTree($allowEmpty, []);
            }
            
            // 检查是否以点开头（如 .1, .2），表示展开当前菜单的子菜单
            if (preg_match('/^\.\d+$/', $choice)) {
                $subIndex = (int)substr($choice, 1);
                if ($subIndex > 0 && $subIndex <= count($menuList)) {
                    $selectedMenu = $menuList[$subIndex - 1];
                    $selectedNode = $selectedMenu['node'];
                    
                    // 展开子菜单
                    $newPath = [$selectedMenu['source']];
                    return $this->selectParentMenuWithTree($allowEmpty, $newPath);
                }
            }
            
            // 单数字选择 - 直接选择该菜单（不展开）
            if (preg_match('/^\d+$/', $choice)) {
                $choiceNum = (int)$choice;
                if ($choiceNum > 0 && $choiceNum <= count($menuList)) {
                    $selectedMenu = $menuList[$choiceNum - 1];
                    // 直接返回选中的菜单，不展开
                    return $selectedMenu['source'];
                }
            }
            
            return '';
        } else {
            // 根据路径找到当前节点
            $currentNode = $tree;
            foreach ($currentPath as $pathSource) {
                if (isset($currentNode[$pathSource])) {
                    $currentNode = $currentNode[$pathSource]['children'];
                } else {
                    // 路径无效，返回顶级
                    return $this->selectParentMenuWithTree($allowEmpty, []);
                }
            }
            
            // 显示当前路径
            $pathTitles = [];
            foreach ($currentPath as $pathSource) {
                if (isset($allMenus[$pathSource])) {
                    $pathTitles[] = $allMenus[$pathSource]['title'];
                }
            }
            $pathStr = implode(' > ', $pathTitles);
            
            if (empty($currentNode)) {
                // 当前节点没有子菜单，显示当前路径让用户确认选择
                // 不直接返回，而是显示一个提示让用户确认
                $this->printer->note(__('当前菜单没有子菜单'));
                $this->printer->note(__('当前路径：%{1}', [$pathStr]));
                $this->printer->note(__('是否选择当前菜单作为父级？(y/n，默认：y)'));
                $confirm = trim(strtolower($this->system->input()));
                if ($confirm === '' || $confirm === 'y' || $confirm === 'yes') {
                    return end($currentPath);
                } else {
                    // 返回上级重新选择
                    if (count($currentPath) > 1) {
                        array_pop($currentPath);
                        return $this->selectParentMenuWithTree($allowEmpty, $currentPath);
                    } else {
                        return $this->selectParentMenuWithTree($allowEmpty, []);
                    }
                }
            }
            $this->printer->note(__('当前路径：%{1}', [$pathStr]));
            $this->printer->note(__('请选择子菜单（输入 0 返回上级，留空选择当前路径，输入数字选择菜单，输入.1/.2等展开子菜单）：'));
            
            // 按照order字段排序子菜单
            uasort($currentNode, function($a, $b) {
                $orderA = (int)($a['menu']['order'] ?? 100);
                $orderB = (int)($b['menu']['order'] ?? 100);
                if ($orderA === $orderB) {
                    // 如果order相同，按照title排序
                    return strcmp($a['menu']['title'] ?? '', $b['menu']['title'] ?? '');
                }
                return $orderA <=> $orderB;
            });
            
            $index = 1;
            $menuList = [];
            foreach ($currentNode as $source => $node) {
                $menu = $node['menu'];
                $title = $menu['title'] ?? '';
                $hasChildren = !empty($node['children']);
                $indicator = $hasChildren ? ' ▶' : '';
                $this->printer->printing("  {$index}. {$title} ({$menu['source']}){$indicator}\n");
                $menuList[] = ['source' => $source, 'node' => $node];
                $index++;
            }
            
            $choice = trim($this->system->input());
            
            if (empty($choice)) {
                // 留空，选择当前路径的最后一个菜单
                return end($currentPath);
            }
            
            // 优先检查是否是返回上级（必须在其他数字检查之前）
            if ($choice === '0') {
                // 返回上级
                if (count($currentPath) > 1) {
                    array_pop($currentPath);
                    return $this->selectParentMenuWithTree($allowEmpty, $currentPath);
                } else {
                    return $this->selectParentMenuWithTree($allowEmpty, []);
                }
            }
            
            // 检查是否以点开头（如 .1, .2），表示展开当前路径下的子菜单
            if (preg_match('/^\.\d+$/', $choice)) {
                $subIndex = (int)substr($choice, 1);
                if ($subIndex > 0 && $subIndex <= count($menuList)) {
                    $selectedMenu = $menuList[$subIndex - 1];
                    $selectedNode = $selectedMenu['node'];
                    
                    // 展开子菜单
                    $newPath = array_merge($currentPath, [$selectedMenu['source']]);
                    return $this->selectParentMenuWithTree($allowEmpty, $newPath);
                }
            }
            
            // 单数字选择 - 直接选择该菜单（不展开）
            // 注意：0已经被处理为返回上级，这里不会匹配到0
            if (preg_match('/^\d+$/', $choice)) {
                $choiceNum = (int)$choice;
                // 确保不是0（0已经在上面处理为返回上级）
                if ($choiceNum > 0 && $choiceNum <= count($menuList)) {
                    $selectedMenu = $menuList[$choiceNum - 1];
                    // 直接返回选中的菜单，不展开
                    return $selectedMenu['source'];
                }
            }
            
            // 如果输入无效，返回当前路径的最后一个菜单
            return end($currentPath);
        }
    }
    
    /**
     * 快速选择菜单（支持1.1、2.1这样的多级选择）
     * @param string $choice 用户输入的选择（如 "1.1", "2.1", "1.2.3"）
     * @param array $tree 菜单树（顶级菜单）
     * @param array $allMenus 所有菜单
     * @return string|null 返回选中的菜单source，如果选择无效返回null
     */
    private function quickSelectMenu(string $choice, array $tree, array $allMenus): ?string
    {
        // 将点号分隔的选择拆分为数字数组
        $indices = explode('.', $choice);
        $currentLevel = $tree;
        $selectedSource = null;
        
        foreach ($indices as $indexStr) {
            $index = (int)$indexStr;
            if ($index < 1) {
                return null;
            }
            
            // 将树转换为数组（保持顺序）
            $menuList = [];
            foreach ($currentLevel as $source => $node) {
                $menuList[] = ['source' => $source, 'node' => $node];
            }
            
            if ($index > count($menuList)) {
                return null;
            }
            
            $selected = $menuList[$index - 1];
            $selectedSource = $selected['source'];
            $selectedNode = $selected['node'];
            
            // 如果还有下一级，继续遍历
            if (!empty($selectedNode['children'])) {
                $currentLevel = $selectedNode['children'];
            } else {
                // 没有子菜单了，返回当前选中的
                break;
            }
        }
        
        return $selectedSource;
    }
    
    /**
     * 从指定路径快速选择菜单
     * @param array $pathParts 路径部分（如 [1, 1, 2]）
     * @param array $tree 菜单树
     * @param array $allMenus 所有菜单
     * @param array $currentPath 当前路径
     * @return string|null 返回选中的菜单source，如果选择无效返回null
     */
    private function quickSelectMenuFromPath(array $pathParts, array $tree, array $allMenus, array $currentPath): ?string
    {
        // 从当前路径开始
        $currentLevel = $tree;
        foreach ($currentPath as $pathSource) {
            if (isset($currentLevel[$pathSource])) {
                $currentLevel = $currentLevel[$pathSource]['children'];
            } else {
                return null;
            }
        }
        
        $selectedSource = null;
        foreach ($pathParts as $indexStr) {
            $index = (int)$indexStr;
            if ($index < 1) {
                return null;
            }
            
            // 将树转换为数组（保持顺序）
            $menuList = [];
            foreach ($currentLevel as $source => $node) {
                $menuList[] = ['source' => $source, 'node' => $node];
            }
            
            if ($index > count($menuList)) {
                return null;
            }
            
            $selected = $menuList[$index - 1];
            $selectedSource = $selected['source'];
            $selectedNode = $selected['node'];
            
            // 如果还有下一级，继续遍历
            if (!empty($selectedNode['children'])) {
                $currentLevel = $selectedNode['children'];
            } else {
                // 没有子菜单了，返回当前选中的
                break;
            }
        }
        
        return $selectedSource;
    }
    
    /**
     * 获取模块状态（安装和启用状态）
     */
    private function getModuleStatus(string $moduleName): array
    {
        $status = [
            'installed' => false,
            'enabled' => false
        ];
        
        try {
            $modules = Env::getInstance()->getModuleList();
            if (isset($modules[$moduleName])) {
                $status['installed'] = true;
                $status['enabled'] = !empty($modules[$moduleName]['status']);
            }
        } catch (\Exception $e) {
            // 静默处理错误
        }
        
        return $status;
    }
    
    /**
     * 安装模块到系统
     */
    private function installModule(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 安装模块到系统 ==='));
        
        $registerFile = $modulePath . DS . 'register.php';
        if (!file_exists($registerFile)) {
            $this->printer->error(__('模块注册文件不存在：%{1}', [$registerFile]));
            $this->printer->note(__('请先创建模块的 register.php 文件'));
            return;
        }
        
        try {
            // 1. 清除缓存
            $this->printer->note(__('步骤 1/3：清除缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            
            // 2. 执行注册文件
            $this->printer->note(__('步骤 2/3：执行模块注册...'));
            require_once $registerFile;
            
            // 3. 运行 setup:upgrade 安装模块
            $this->printer->note(__('步骤 3/3：安装模块（运行 setup:upgrade）...'));
            $command = 'php ' . BP . 'bin' . DS . 'w setup:upgrade ' . escapeshellarg($moduleName);
            $output = [];
            $returnVar = 0;
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->printer->success(__('模块 %{1} 已成功安装到系统！', [$moduleName]));
                if (!empty($output)) {
                    // 显示关键输出信息
                    foreach ($output as $line) {
                        if (stripos($line, 'error') !== false || stripos($line, 'success') !== false || stripos($line, '完成') !== false) {
                            $this->printer->printing($line . "\n");
                        }
                    }
                }
            } else {
                $this->printer->warning(__('模块安装可能未完全完成'));
                if (!empty($output)) {
                    $this->printer->printing(implode("\n", $output) . "\n");
                }
                $this->printer->note(__('请手动运行以下命令安装模块：'));
                $this->printer->printing("  php bin/w setup:upgrade {$moduleName}\n");
            }
        } catch (\Exception $e) {
            $this->printer->error(__('安装模块时出错：%{1}', [$e->getMessage()]));
            $this->printer->note(__('请手动运行以下命令安装模块：'));
            $this->printer->printing("  php bin/w setup:upgrade {$moduleName}\n");
        }
    }
    
    /**
     * 重装模块
     */
    private function reinstallModule(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 重装模块 ==='));
        $this->printer->warning(__('⚠️  警告：重装模块将执行以下操作：'));
        $this->printer->warning(__('  1. 清除系统缓存'));
        $this->printer->warning(__('  2. 重新执行模块的安装脚本（setup/install.php）'));
        $this->printer->warning(__('  3. 重新执行模块的升级脚本（setup/upgrade.php）'));
        $this->printer->warning(__('  4. 可能会影响数据库数据（表结构、数据等）'));
        $this->printer->warning(__('  5. 可能会影响已安装的配置'));
        $this->printer->note('');
        $this->printer->note(__('请确认是否要继续重装模块 %{1}？', [$moduleName]));
        $this->printer->note(__('输入 "yes" 或 "y" 确认，其他任何输入将取消操作（默认：取消）'));
        $confirm = trim(strtolower($this->system->input()));
        
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printer->note(__('已取消重装操作'));
            return;
        }
        
        // 二次确认
        $this->printer->warning(__('⚠️  最后确认：您确定要重装模块 %{1} 吗？', [$moduleName]));
        $this->printer->note(__('再次输入 "yes" 或 "y" 确认，其他任何输入将取消操作'));
        $confirm2 = trim(strtolower($this->system->input()));
        
        if ($confirm2 !== 'y' && $confirm2 !== 'yes') {
            $this->printer->note(__('已取消重装操作'));
            return;
        }
        
        $registerFile = $modulePath . DS . 'register.php';
        if (!file_exists($registerFile)) {
            $this->printer->error(__('模块注册文件不存在：%{1}', [$registerFile]));
            return;
        }
        
        try {
            // 1. 清除缓存
            $this->printer->note(__('步骤 1/4：清除缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            
            // 2. 执行注册文件
            $this->printer->note(__('步骤 2/4：执行模块注册...'));
            require_once $registerFile;
            
            // 3. 标记模块为需要重装（通过修改模块状态）
            $this->printer->note(__('步骤 3/4：标记模块为需要重装...'));
            // 这里需要调用模块管理器来标记模块状态
            // 由于框架可能没有直接的重装标记，我们通过运行 setup:upgrade 来实现
            
            // 4. 运行 setup:upgrade 重装模块
            $this->printer->note(__('步骤 4/4：重装模块（运行 setup:upgrade）...'));
            $command = 'php ' . BP . 'bin' . DS . 'w setup:upgrade ' . escapeshellarg($moduleName);
            $output = [];
            $returnVar = 0;
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->printer->success(__('模块 %{1} 已成功重装！', [$moduleName]));
                if (!empty($output)) {
                    // 显示关键输出信息
                    foreach ($output as $line) {
                        if (stripos($line, 'error') !== false || stripos($line, 'success') !== false || stripos($line, '完成') !== false || stripos($line, '安装') !== false) {
                            $this->printer->printing($line . "\n");
                        }
                    }
                }
            } else {
                $this->printer->warning(__('模块重装可能未完全完成'));
                if (!empty($output)) {
                    $this->printer->printing(implode("\n", $output) . "\n");
                }
                $this->printer->note(__('请手动运行以下命令重装模块：'));
                $this->printer->printing("  php bin/w setup:upgrade {$moduleName}\n");
            }
        } catch (\Exception $e) {
            $this->printer->error(__('重装模块时出错：%{1}', [$e->getMessage()]));
            $this->printer->note(__('请手动运行以下命令重装模块：'));
            $this->printer->printing("  php bin/w setup:upgrade {$moduleName}\n");
        }
    }
    
    /**
     * 卸载模块
     */
    private function uninstallModule(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 卸载模块 ==='));
        
        // 检查模块是否已安装
        $moduleStatus = $this->getModuleStatus($moduleName);
        if (!$moduleStatus['installed']) {
            $this->printer->warning(__('模块 %{1} 未安装，无需卸载', [$moduleName]));
            return;
        }
        
        // 显示警告信息
        $this->printer->printing("\n");
        $this->printer->error(__('═══════════════════════════════════════════════════════'));
        $this->printer->error(__('⚠️  危险操作警告：卸载模块'));
        $this->printer->error(__('═══════════════════════════════════════════════════════'));
        $this->printer->printing("\n");
        $this->printer->warning(__('模块名称：%{1}', [$moduleName]));
        $this->printer->warning(__('模块路径：%{1}', [$modulePath]));
        $this->printer->printing("\n");
        $this->printer->warning(__('⚠️  卸载模块将执行以下操作：'));
        $this->printer->warning(__('  1. 执行模块的卸载脚本（Setup/Remove.php）'));
        $this->printer->warning(__('  2. 清理模块的数据库表和数据（如果卸载脚本中有定义）'));
        $this->printer->warning(__('  3. 从系统中移除模块注册'));
        $this->printer->warning(__('  4. 清除系统缓存'));
        $this->printer->warning(__('  5. 备份模块文件到 var/backup 目录'));
        $this->printer->warning(__('  6. 不会删除模块源代码文件（如需删除请手动操作）'));
        $this->printer->printing("\n");
        $this->printer->error(__('⚠️  重要提示：'));
        $this->printer->error(__('  - 卸载操作不可逆，请确保已备份重要数据'));
        $this->printer->error(__('  - 如果模块有依赖关系，卸载可能会影响其他模块'));
        $this->printer->error(__('  - 模块文件不会被删除，但模块将从系统中移除'));
        $this->printer->error(__('  - 卸载后如需重新安装，需要重新运行安装命令'));
        $this->printer->printing("\n");
        $this->printer->error(__('═══════════════════════════════════════════════════════'));
        $this->printer->printing("\n");
        
        // 第一次确认
        $this->printer->error(__('⚠️  第一次确认：您确定要卸载模块 %{1} 吗？', [$moduleName]));
        $this->printer->note(__('请输入完整的模块名称 "%{1}" 以确认卸载', [$moduleName]));
        $this->printer->note(__('或者输入 "yes" 或 "y" 确认，其他任何输入将取消操作'));
        $this->printer->printing("\n");
        $confirm = trim($this->system->input());
        $confirmLower = strtolower($confirm);
        
        // 检查确认输入：必须是模块名、yes 或 y
        if ($confirm !== $moduleName && $confirmLower !== 'y' && $confirmLower !== 'yes') {
            $this->printer->note(__('已取消卸载操作（确认输入不匹配）'));
            return;
        }
        
        // 二次确认
        $this->printer->printing("\n");
        $this->printer->error(__('═══════════════════════════════════════════════════════'));
        $this->printer->error(__('⚠️  最后确认：您确定要卸载模块 %{1} 吗？', [$moduleName]));
        $this->printer->error(__('═══════════════════════════════════════════════════════'));
        $this->printer->printing("\n");
        $this->printer->error(__('此操作将执行卸载脚本并移除模块注册，但不会删除源代码文件'));
        $this->printer->error(__('卸载后，模块将从系统中完全移除，无法恢复！'));
        $this->printer->printing("\n");
        $this->printer->error(__('⚠️  请再次输入完整的模块名称 "%{1}" 以最终确认卸载', [$moduleName]));
        $this->printer->note(__('或者输入 "yes" 或 "y" 确认，其他任何输入将取消操作'));
        $this->printer->printing("\n");
        $confirm2 = trim($this->system->input());
        $confirm2Lower = strtolower($confirm2);
        
        // 检查二次确认输入：必须是模块名、yes 或 y
        if ($confirm2 !== $moduleName && $confirm2Lower !== 'y' && $confirm2Lower !== 'yes') {
            $this->printer->note(__('已取消卸载操作（二次确认输入不匹配）'));
            return;
        }
        
        $this->printer->printing("\n");
        $this->printer->warning(__('确认通过，开始卸载模块...'));
        $this->printer->printing("\n");
        
        // 创建备份记录ID（使用时间戳作为唯一标识）
        $backupId = 'uninstall_' . $moduleName . '_' . date('Y-m-d_H-i-s');
        $backupInfoFile = BP . 'var' . DS . 'backup' . DS . 'module_uninstall' . DS . $backupId . '.json';
        
        try {
            // 0. 备份数据库数据（在卸载前）
            $this->printer->note(__('步骤 0/6：备份模块数据库数据...'));
            $backupInfo = $this->backupModuleDatabase($moduleName, $backupId, $backupInfoFile);
            if ($backupInfo['success']) {
                $this->printer->success(__('数据库备份完成：备份了 %{1} 个表，共 %{2} 条记录', [
                    $backupInfo['table_count'],
                    $backupInfo['record_count']
                ]));
                $this->printer->note(__('备份信息已保存到：%{1}', [$backupInfoFile]));
            } else {
                $this->printer->warning(__('数据库备份可能未完全完成：%{1}', [$backupInfo['message'] ?? '']));
            }
            
            // 1. 执行卸载脚本
            $this->printer->note(__('步骤 1/6：执行模块卸载脚本...'));
            $removeFile = $modulePath . DS . 'Setup' . DS . 'Remove.php';
            if (file_exists($removeFile)) {
                $this->printer->note(__('找到卸载脚本，正在执行...'));
                // 卸载脚本会通过 module:remove 命令自动执行
            } else {
                $this->printer->note(__('未找到卸载脚本（Setup/Remove.php），将跳过卸载脚本执行'));
            }
            
            // 2. 运行 module:remove 命令卸载模块
            $this->printer->note(__('步骤 2/6：运行模块卸载命令...'));
            $command = 'php ' . BP . 'bin' . DS . 'w module:remove ' . escapeshellarg($moduleName);
            $output = [];
            $returnVar = 0;
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->printer->success(__('模块卸载命令执行成功'));
                if (!empty($output)) {
                    // 显示关键输出信息
                    foreach ($output as $line) {
                        if (stripos($line, 'error') !== false || 
                            stripos($line, 'success') !== false || 
                            stripos($line, '完成') !== false ||
                            stripos($line, '卸载') !== false) {
                            $this->printer->printing($line . "\n");
                        }
                    }
                }
            } else {
                $this->printer->warning(__('模块卸载命令可能未完全完成'));
                if (!empty($output)) {
                    $this->printer->printing(implode("\n", $output) . "\n");
                }
            }
            
            // 3. 清除缓存
            $this->printer->note(__('步骤 3/6：清除系统缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            if ($cacheReturnVar === 0) {
                $this->printer->success(__('缓存清除完成'));
            } else {
                $this->printer->warning(__('缓存清除可能未完全完成'));
            }
            
            // 4. 刷新菜单收集（移除模块菜单）
            $this->printer->note(__('步骤 4/6：刷新菜单收集（移除模块菜单）...'));
            $menuCollectCommand = 'php ' . BP . 'bin' . DS . 'w backend:menu:collect';
            exec($menuCollectCommand . ' 2>&1', $menuOutput, $menuReturnVar);
            if ($menuReturnVar === 0) {
                $this->printer->success(__('菜单收集完成'));
            } else {
                $this->printer->warning(__('菜单收集可能未完全完成'));
            }
            
            // 5. 运行 setup:upgrade 更新模块列表
            $this->printer->note(__('步骤 5/6：更新模块列表...'));
            $upgradeCommand = 'php ' . BP . 'bin' . DS . 'w setup:upgrade';
            exec($upgradeCommand . ' 2>&1', $upgradeOutput, $upgradeReturnVar);
            if ($upgradeReturnVar === 0) {
                $this->printer->success(__('模块列表更新完成'));
            } else {
                $this->printer->warning(__('模块列表更新可能未完全完成'));
            }
            
            // 6. 保存卸载信息到备份文件
            $this->printer->note(__('步骤 6/6：保存卸载信息...'));
            $uninstallInfo = [
                'backup_id' => $backupId,
                'module_name' => $moduleName,
                'module_path' => $modulePath,
                'uninstall_time' => date('Y-m-d H:i:s'),
                'backup_info' => $backupInfo,
                'can_rollback' => true
            ];
            
            // 确保备份目录存在
            $backupDir = dirname($backupInfoFile);
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            file_put_contents($backupInfoFile, json_encode($uninstallInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->printer->success(__('卸载信息已保存'));
            
            // 验证卸载结果
            $finalStatus = $this->getModuleStatus($moduleName);
            if (!$finalStatus['installed']) {
                $this->printer->printing("\n");
                $this->printer->success(__('═══════════════════════════════════════════════════════'));
                $this->printer->success(__('模块 %{1} 已成功卸载！', [$moduleName]));
                $this->printer->success(__('═══════════════════════════════════════════════════════'));
                $this->printer->printing("\n");
                $this->printer->note(__('提示：模块源代码文件仍保留在 %{1}，如需删除请手动操作', [$modulePath]));
                $this->printer->note(__('备份信息已保存到：%{1}', [$backupInfoFile]));
                $this->printer->note(__('如需回滚，请使用以下命令：'));
                $this->printer->printing("  php bin/w module:create -m {$moduleName}\n");
                $this->printer->printing("  然后选择选项：回滚模块卸载\n");
            } else {
                $this->printer->warning(__('模块卸载可能未完全完成，模块状态仍显示为已安装'));
                $this->printer->note(__('请手动运行以下命令完成卸载：'));
                $this->printer->printing("  php bin/w module:remove {$moduleName}\n");
                $this->printer->printing("  php bin/w setup:upgrade\n");
            }
            
        } catch (\Exception $e) {
            $this->printer->error(__('卸载模块时出错：%{1}', [$e->getMessage()]));
            $this->printer->note(__('请手动运行以下命令卸载模块：'));
            $this->printer->printing("  php bin/w module:remove {$moduleName}\n");
            $this->printer->printing("  php bin/w setup:upgrade\n");
            // 如果备份已创建，提示可以回滚
            if (isset($backupInfoFile) && file_exists($backupInfoFile)) {
                $this->printer->note(__('备份信息已保存，如需回滚请使用回滚功能'));
            }
        }
    }
    
    /**
     * 备份模块数据库数据
     * 
     * @param string $moduleName 模块名称
     * @param string $backupId 备份ID
     * @param string $backupInfoFile 备份信息文件路径
     * @return array 备份信息
     */
    private function backupModuleDatabase(string $moduleName, string $backupId, string $backupInfoFile): array
    {
        $backupInfo = [
            'success' => false,
            'table_count' => 0,
            'record_count' => 0,
            'tables' => [],
            'message' => ''
        ];
        
        try {
            // 获取模块的所有表
            $moduleTableModel = ObjectManager::getInstance('Weline\ModuleManager\Model\Module\Table');
            $collection = $moduleTableModel->getCollection();
            $collection->addFieldToFilter('module_name', $moduleName);
            $moduleTables = $collection->getItems();
            
            if (empty($moduleTables)) {
                $backupInfo['message'] = __('模块没有注册的数据库表');
                $backupInfo['success'] = true;
                return $backupInfo;
            }
            
            // 获取数据库连接
            $connectionFactory = ObjectManager::getInstance('Weline\Framework\Database\ConnectionFactory');
            $connection = $connectionFactory->getConnection();
            
            // 备份每个表的数据
            $backupDir = BP . 'var' . DS . 'backup' . DS . 'module_uninstall' . DS . $backupId;
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            foreach ($moduleTables as $moduleTable) {
                $tableName = $moduleTable->getData('name');
                if (empty($tableName)) {
                    continue;
                }
                
                try {
                    // 检查表是否存在
                    $tableExists = $connection->query("SHOW TABLES LIKE '{$tableName}'")->rowCount() > 0;
                    if (!$tableExists) {
                        $this->printer->note(__('表 %{1} 不存在，跳过备份', [$tableName]));
                        continue;
                    }
                    
                    // 备份表结构和数据
                    $backupFile = $backupDir . DS . $tableName . '.sql';
                    $this->backupTableToFile($connection, $tableName, $backupFile);
                    
                    // 统计记录数
                    $recordCount = $connection->query("SELECT COUNT(*) as cnt FROM `{$tableName}`")->fetch()['cnt'] ?? 0;
                    
                    $backupInfo['tables'][] = [
                        'table_name' => $tableName,
                        'backup_file' => $backupFile,
                        'record_count' => (int)$recordCount
                    ];
                    $backupInfo['table_count']++;
                    $backupInfo['record_count'] += (int)$recordCount;
                    
                    $this->printer->note(__('  ✓ 已备份表 %{1}：%{2} 条记录', [$tableName, $recordCount]));
                    
                } catch (\Exception $e) {
                    $this->printer->warning(__('备份表 %{1} 失败：%{2}', [$tableName, $e->getMessage()]));
                }
            }
            
            $backupInfo['success'] = true;
            $backupInfo['backup_dir'] = $backupDir;
            
        } catch (\Exception $e) {
            $backupInfo['message'] = $e->getMessage();
            $this->printer->error(__('备份数据库时出错：%{1}', [$e->getMessage()]));
        }
        
        return $backupInfo;
    }
    
    /**
     * 备份表到文件
     * 
     * @param mixed $connection 数据库连接
     * @param string $tableName 表名
     * @param string $backupFile 备份文件路径
     * @return void
     */
    private function backupTableToFile($connection, string $tableName, string $backupFile): void
    {
        $file = fopen($backupFile, 'w');
        if (!$file) {
            throw new \Exception(__('无法创建备份文件：%{1}', [$backupFile]));
        }
        
        try {
            // 1. 备份表结构
            $createTableResult = $connection->query("SHOW CREATE TABLE `{$tableName}`")->fetch();
            if ($createTableResult) {
                $createTableSql = $createTableResult['Create Table'] ?? '';
                fwrite($file, "-- 表结构：{$tableName}\n");
                fwrite($file, "DROP TABLE IF EXISTS `{$tableName}`;\n");
                fwrite($file, $createTableSql . ";\n\n");
            }
            
            // 2. 备份表数据
            $rows = $connection->query("SELECT * FROM `{$tableName}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                fwrite($file, "-- 表数据：{$tableName}\n");
                fwrite($file, "LOCK TABLES `{$tableName}` WRITE;\n");
                
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $values = array_map(function($value) use ($connection) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return $connection->quote($value);
                    }, array_values($row));
                    
                    $sql = "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    fwrite($file, $sql);
                }
                
                fwrite($file, "UNLOCK TABLES;\n\n");
            }
            
        } finally {
            fclose($file);
        }
    }
    
    /**
     * 回滚模块卸载
     * 
     * @param string $moduleName 模块名称
     * @param string $modulePath 模块路径
     * @return void
     */
    private function rollbackModuleUninstall(string $moduleName, string $modulePath): void
    {
        $this->printer->note(__('=== 回滚模块卸载 ==='));
        
        // 查找备份信息文件
        $backupDir = BP . 'var' . DS . 'backup' . DS . 'module_uninstall';
        if (!is_dir($backupDir)) {
            $this->printer->error(__('备份目录不存在，无法回滚'));
            return;
        }
        
        // 查找该模块的备份文件
        $backupFiles = glob($backupDir . DS . 'uninstall_' . $moduleName . '_*.json');
        if (empty($backupFiles)) {
            $this->printer->error(__('未找到模块 %{1} 的卸载备份，无法回滚', [$moduleName]));
            return;
        }
        
        // 选择最新的备份
        rsort($backupFiles);
        $latestBackupFile = $backupFiles[0];
        $backupInfo = json_decode(file_get_contents($latestBackupFile), true);
        
        if (empty($backupInfo) || !isset($backupInfo['backup_info'])) {
            $this->printer->error(__('备份信息文件损坏，无法回滚'));
            return;
        }
        
        $this->printer->note(__('找到备份：%{1}', [basename($latestBackupFile)]));
        $this->printer->note(__('备份时间：%{1}', [$backupInfo['uninstall_time'] ?? '']));
        $this->printer->note(__('备份表数：%{1}，记录数：%{2}', [
            $backupInfo['backup_info']['table_count'] ?? 0,
            $backupInfo['backup_info']['record_count'] ?? 0
        ]));
        $this->printer->printing("\n");
        
        // 确认回滚
        $this->printer->warning(__('⚠️  警告：回滚将执行以下操作：'));
        $this->printer->warning(__('  1. 恢复模块的数据库表和数据'));
        $this->printer->warning(__('  2. 重新注册模块到系统'));
        $this->printer->warning(__('  3. 清除系统缓存'));
        $this->printer->note('');
        $this->printer->note(__('请确认是否要继续回滚？'));
        $this->printer->note(__('输入 "yes" 或 "y" 确认，其他任何输入将取消操作'));
        $confirm = trim(strtolower($this->system->input()));
        
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printer->note(__('已取消回滚操作'));
            return;
        }
        
        try {
            // 1. 恢复数据库表和数据
            $this->printer->note(__('步骤 1/3：恢复数据库表和数据...'));
            $this->restoreModuleDatabase($backupInfo['backup_info']);
            
            // 2. 重新安装模块
            $this->printer->note(__('步骤 2/3：重新安装模块...'));
            $this->installModule($moduleName, $modulePath);
            
            // 3. 清除缓存
            $this->printer->note(__('步骤 3/3：清除系统缓存...'));
            $cacheClearCommand = 'php ' . BP . 'bin' . DS . 'w cache:clear -f';
            exec($cacheClearCommand . ' 2>&1', $cacheOutput, $cacheReturnVar);
            
            $this->printer->printing("\n");
            $this->printer->success(__('═══════════════════════════════════════════════════════'));
            $this->printer->success(__('模块 %{1} 回滚成功！', [$moduleName]));
            $this->printer->success(__('═══════════════════════════════════════════════════════'));
            
        } catch (\Exception $e) {
            $this->printer->error(__('回滚时出错：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 恢复模块数据库
     * 
     * @param array $backupInfo 备份信息
     * @return void
     */
    private function restoreModuleDatabase(array $backupInfo): void
    {
        if (empty($backupInfo['tables'])) {
            $this->printer->note(__('没有需要恢复的表'));
            return;
        }
        
        $connectionFactory = ObjectManager::getInstance('Weline\Framework\Database\ConnectionFactory');
        $connection = $connectionFactory->getConnection();
        
        foreach ($backupInfo['tables'] as $tableInfo) {
            $tableName = $tableInfo['table_name'];
            $backupFile = $tableInfo['backup_file'];
            
            if (!file_exists($backupFile)) {
                $this->printer->warning(__('备份文件不存在：%{1}，跳过表 %{2}', [$backupFile, $tableName]));
                continue;
            }
            
            try {
                // 读取并执行SQL文件
                $sql = file_get_contents($backupFile);
                if (empty($sql)) {
                    $this->printer->warning(__('备份文件为空：%{1}，跳过表 %{2}', [$backupFile, $tableName]));
                    continue;
                }
                
                // 执行SQL（需要分割多个语句）
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement) && !preg_match('/^--/', $statement)) {
                        try {
                            $connection->exec($statement);
                        } catch (\Exception $e) {
                            // 忽略某些错误（如表已存在等）
                            if (stripos($e->getMessage(), 'already exists') === false) {
                                throw $e;
                            }
                        }
                    }
                }
                
                $this->printer->success(__('  ✓ 已恢复表 %{1}：%{2} 条记录', [
                    $tableName,
                    $tableInfo['record_count'] ?? 0
                ]));
                
            } catch (\Exception $e) {
                $this->printer->error(__('恢复表 %{1} 失败：%{2}', [$tableName, $e->getMessage()]));
            }
        }
    }

    /**
     * 扫描模块的Acl路由
     */
    private function scanModuleAclRoutes(string $modulePath, string $moduleName): array
    {
        $routes = [];
        
        if (!is_dir($modulePath)) {
            return $routes;
        }
        
        // 获取模块路由前缀
        $router = $this->moduleConfig['router'] ?? strtolower(str_replace('_', '', $moduleName));
        
        // 扫描Controller目录
        $controllerDirs = [
            $modulePath . DS . 'Controller' . DS . 'Backend',
            $modulePath . DS . 'Api' . DS . 'Rest' . DS . 'V1' . DS . 'Backend',
        ];
        
        foreach ($controllerDirs as $controllerDir) {
            if (!is_dir($controllerDir)) {
                continue;
            }
            
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($controllerDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $aclInfo = $this->extractAclFromFile($file->getPathname(), $moduleName, $router);
                        if (!empty($aclInfo)) {
                            $routes = array_merge($routes, $aclInfo);
                        }
                    }
                }
            } catch (\Exception $e) {
                // 忽略错误
                continue;
            }
        }
        
        return $routes;
    }

    /**
     * 从PHP文件中提取Acl信息
     */
    private function extractAclFromFile(string $filePath, string $moduleName, string $router): array
    {
        $routes = [];
        
        try {
            $content = file_get_contents($filePath);
            
            // 提取命名空间和类名
            $namespace = '';
            $className = '';
            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                $namespace = $nsMatches[1];
            }
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = $classMatches[1];
            }
            
            if (empty($className)) {
                return $routes;
            }
            
            // 提取类级别的Acl（在class关键字之前）
            $classAcl = null;
            if (preg_match('/class\s+' . preg_quote($className, '/') . '/', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
                $classPos = $classMatch[0][1];
                $beforeClass = substr($content, max(0, $classPos - 1000), 1000);
                $classAcl = $this->extractAclAttribute($beforeClass, 'class');
            }
            
            // 提取方法级别的Acl
            // 先找到所有方法定义
            if (preg_match_all('/(?:public|protected|private)?\s*function\s+(\w+)\s*\(/', $content, $methodMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($methodMatches[1] as $methodMatch) {
                    $methodName = $methodMatch[0];
                    $methodOffset = $methodMatch[1];
                    
                    // 获取方法前的内容（最多1000字符）
                    $methodStart = $methodOffset;
                    $beforeMethod = substr($content, max(0, $methodStart - 1000), 1000);
                    
                    // 提取方法上的Acl（查找最近的Acl注解）
                    $methodAcl = $this->extractAclAttribute($beforeMethod, 'method');
                    
                    if (!empty($methodAcl)) {
                        // 构建路由路径
                        $routePath = $this->buildRouteFromNamespace($namespace, $className, $methodName, $router);
                        
                        $routes[] = [
                            'source_id' => $methodAcl['source_id'] ?? '',
                            'source_name' => $methodAcl['source_name'] ?? '',
                            'icon' => $methodAcl['icon'] ?? '',
                            'route' => $routePath,
                            'controller' => $className,
                            'action' => $methodName,
                        ];
                    }
                }
            }
            
            // 如果没有方法级别的Acl，使用类级别的Acl
            if (empty($routes) && !empty($classAcl)) {
                $routePath = $this->buildRouteFromNamespace($namespace, $className, 'index', $router);
                $routes[] = [
                    'source_id' => $classAcl['source_id'] ?? '',
                    'source_name' => $classAcl['source_name'] ?? '',
                    'icon' => $classAcl['icon'] ?? '',
                    'route' => $routePath,
                    'controller' => $className,
                    'action' => 'index',
                ];
            }
            
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        return $routes;
    }

    /**
     * 提取Acl属性
     */
    private function extractAclAttribute(string $content, string $type = 'method'): ?array
    {
        // 匹配两种格式：
        // 1. #[Acl(...)] - 简化格式（使用use语句）
        // 2. #[\Weline\Framework\Acl\Acl(...)] - 完整格式
        
        // 先尝试匹配简化格式
        $pattern1 = '/#\[Acl\s*\(\s*([^)]+)\s*\)\]/s';
        // 再尝试匹配完整格式
        $pattern2 = '/#\[\\\?Weline\\\\Framework\\\\Acl\\\\Acl\s*\(\s*([^)]+)\s*\)\]/s';
        
        $matches = null;
        if (preg_match($pattern1, $content, $matches)) {
            // 找到简化格式
        } elseif (preg_match($pattern2, $content, $matches)) {
            // 找到完整格式
        } else {
            return null;
        }
        
        $params = $matches[1];
        
        // 解析参数（可能是命名参数或位置参数）
        // 位置参数格式：'source_id', 'source_name', 'icon', 'document', 'parent_source'
        // 命名参数格式：source_id: '...', source_name: '...'
        
        $acl = [];
        
        // 尝试找到最后一个匹配（最接近方法或类的Acl）
        $allMatches = [];
        if (preg_match_all($pattern1, $content, $allMatches1, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($allMatches1 as $match) {
                $allMatches[] = ['match' => $match[0][0], 'offset' => $match[0][1], 'params' => $match[1][0] ?? ''];
            }
        }
        if (preg_match_all($pattern2, $content, $allMatches2, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($allMatches2 as $match) {
                $allMatches[] = ['match' => $match[0][0], 'offset' => $match[0][1], 'params' => $match[1][0] ?? ''];
            }
        }
        
        if (empty($allMatches)) {
            return null;
        }
        
        // 获取最后一个匹配（最接近方法或类的）
        usort($allMatches, function($a, $b) {
            return $b['offset'] - $a['offset'];
        });
        $lastMatch = reset($allMatches);
        $params = $lastMatch['params'] ?? $matches[1];
        
        // 解析位置参数（支持空字符串）
        // 使用更精确的正则表达式，能够匹配空字符串
        if (preg_match_all("/(?:^|,)\s*['\"]([^'\"]*)['\"]/", $params, $paramMatches)) {
            $values = $paramMatches[1];
            if (count($values) >= 1) {
                $acl['source_id'] = $values[0];
            }
            if (count($values) >= 2) {
                $acl['source_name'] = $values[1];
            }
            if (count($values) >= 3) {
                // 只有当值不为空时才设置 icon
                $iconValue = $values[2];
                if ($iconValue !== '') {
                    $acl['icon'] = $iconValue;
                }
            }
            if (count($values) >= 4) {
                $documentValue = $values[3];
                if ($documentValue !== '') {
                    $acl['document'] = $documentValue;
                }
            }
            if (count($values) >= 5) {
                $parentValue = $values[4];
                if ($parentValue !== '') {
                    $acl['parent_source'] = $parentValue;
                }
            }
        }
        
        // 尝试解析命名参数（优先级更高）
        if (preg_match("/source_id\s*:\s*['\"]([^'\"]+)['\"]/", $params, $m)) {
            $acl['source_id'] = $m[1];
        }
        if (preg_match("/source_name\s*:\s*['\"]([^'\"]+)['\"]/", $params, $m)) {
            $acl['source_name'] = $m[1];
        }
        if (preg_match("/icon\s*:\s*['\"]([^'\"]+)['\"]/", $params, $m)) {
            $acl['icon'] = $m[1];
        }
        if (preg_match("/document\s*:\s*['\"]([^'\"]+)['\"]/", $params, $m)) {
            $acl['document'] = $m[1];
        }
        if (preg_match("/parent_source\s*:\s*['\"]([^'\"]+)['\"]/", $params, $m)) {
            $acl['parent_source'] = $m[1];
        }
        
        return !empty($acl) ? $acl : null;
    }

    /**
     * 从命名空间构建路由
     */
    private function buildRouteFromNamespace(string $namespace, string $className, string $methodName, string $router): string
    {
        // 从命名空间推断路由结构
        // 例如：Weline\Demo\Controller\Backend\Index -> backend/index/index
        
        $parts = explode('\\', $namespace);
        $routeParts = [];
        
        // 查找Controller或Api在命名空间中的位置
        $controllerIndex = -1;
        $apiIndex = -1;
        foreach ($parts as $index => $part) {
            if ($part === 'Controller') {
                $controllerIndex = $index;
            }
            if ($part === 'Api') {
                $apiIndex = $index;
            }
        }
        
        if ($apiIndex >= 0) {
            // API路由：api/rest/v1/backend/controller/action
            $routeParts[] = 'api';
            $routeParts[] = 'rest';
            $routeParts[] = 'v1';
            
            // 检查是否有Backend
            if (in_array('Backend', $parts)) {
                $routeParts[] = 'backend';
            } elseif (in_array('Frontend', $parts)) {
                $routeParts[] = 'frontend';
            }
            
            $routeParts[] = strtolower($className);
            if ($methodName !== 'index') {
                $routeParts[] = strtolower($methodName);
            }
        } elseif ($controllerIndex >= 0) {
            // Controller路由：backend/controller/action
            if (in_array('Backend', $parts)) {
                $routeParts[] = 'backend';
            } elseif (in_array('Frontend', $parts)) {
                $routeParts[] = 'frontend';
            }
            
            $routeParts[] = strtolower($className);
            if ($methodName !== 'index') {
                $routeParts[] = strtolower($methodName);
            }
        } else {
            // 默认路由
            $routeParts[] = strtolower($className);
            if ($methodName !== 'index') {
                $routeParts[] = strtolower($methodName);
            }
        }
        
        $route = $router . '/' . implode('/', $routeParts);
        return $route;
    }

    /**
     * 颜色化文本（支持自定义颜色）
     */
    private function colorizeText(string $text, string $color): string
    {
        $colorCodes = [
            'Blue' => '[34m',
            'Purple' => '[35m',  // 紫色/洋红色
            'Magenta' => '[35m',
        ];
        
        $code = $colorCodes[$color] ?? '[0m';
        return chr(27) . $code . $text . chr(27) . '[0m';
    }

    /**
     * 加粗文本
     */
    private function colorizeBold(string $text): string
    {
        return chr(27) . '[1m' . $text . chr(27) . '[0m';
    }

    /**
     * 选择或创建模块
     * 提供搜索已有模块或自定义新模块的功能
     */
    private function selectOrCreateModule(): ?string
    {
        $this->printer->setup(__('=== 模块选择 ==='));
        $this->printer->printing("\n");
        $this->printer->note(__('请选择操作：'));
        $this->printer->note(__('1. 搜索已有模块'));
        $this->printer->note(__('2. 自定义新模块名'));
        $this->printer->note(__('0. 退出'));
        
        $choice = trim($this->system->input());
        
        switch ($choice) {
            case '1':
                return $this->searchModules();
            case '2':
                return $this->inputCustomModuleName();
            case '3':
                $selectedModule = $this->searchModulesForCheck();
                if ($selectedModule) {
                    $modulePath = APP_CODE_PATH . str_replace('_', DS, $selectedModule);
                    $this->checkModuleIntegrity($selectedModule, $modulePath);
                }
                return null; // 检测完成后返回，不继续创建流程
            case '0':
                $this->printer->note(__('已取消操作'));
                return null;
            default:
                $this->printer->warning(__('无效选项，请重新选择'));
                return $this->selectOrCreateModule();
        }
    }

    /**
     * 搜索模块
     */
    private function searchModules(): ?string
    {
        $this->printer->note(__('请输入搜索关键词（模块名或部分名称）：'));
        $this->printer->note(__('提示：留空将显示所有模块'));
        $searchInput = $this->system->input();
        $search = trim($searchInput);
        
        // 获取所有模块
        $allModules = $this->getAllModules();
        
        if (empty($allModules)) {
            $this->printer->warning(__('未找到任何模块'));
            $this->printer->note(__('是否返回重新选择？(y/n，默认：y)'));
            $retry = trim(strtolower($this->system->input()));
            if ($retry !== 'n' && $retry !== 'no') {
                return $this->selectOrCreateModule();
            }
            return null;
        }
        
        // 如果搜索关键词为空，显示所有模块
        if (empty($search)) {
            $matchedModules = $allModules;
            $this->printer->note(__('显示所有模块（共 %{1} 个）：', [count($matchedModules)]));
        } else {
            // 过滤匹配的模块
            $matchedModules = $this->filterModules($allModules, $search);
            
            // 调试信息：显示搜索关键词和匹配结果数量
            $this->printer->printing("\n");
            $this->printer->note(__('搜索关键词：%{1}', [$search]));
            $this->printer->note(__('总模块数：%{1}，匹配数：%{2}', [count($allModules), count($matchedModules)]));
            
            if (empty($matchedModules)) {
                $this->printer->warning(__('未找到匹配的模块：%{1}', [$search]));
                $this->printer->note(__('是否重新搜索？(y/n，默认：y)'));
                $retry = trim(strtolower($this->system->input()));
                if ($retry !== 'n' && $retry !== 'no') {
                    return $this->searchModules();
                }
                return null;
            }
            
            $this->printer->note(__('找到 %{1} 个匹配的模块：', [count($matchedModules)]));
        }
        
        // 显示匹配的模块列表
        $this->printer->printing("\n");
        $index = 1;
        foreach ($matchedModules as $module) {
            $moduleName = $this->colorizeBold($module['name']);
            $relativePath = str_replace(BP, '', $module['path']);
            $relativePath = ltrim(str_replace('\\', DS, $relativePath), DS);
            
            // 路径着色
            if (strpos($relativePath, 'app' . DS . 'code') === 0) {
                $coloredPath = $this->colorizeText($relativePath, 'Blue');
            } elseif (strpos($relativePath, 'vendor') === 0) {
                $coloredPath = $this->colorizeText($relativePath, 'Purple');
            } else {
                $coloredPath = $relativePath;
            }
            
            $this->printer->printing("  {$index}. {$moduleName} ({$coloredPath})\n");
            $index++;
        }
        
        $this->printer->printing("\n");
        $this->printer->note(__('请选择模块（输入序号，或输入 0 返回）'));
        $choiceInput = trim($this->system->input());
        
        if (empty($choiceInput) || $choiceInput === '0') {
            return $this->selectOrCreateModule();
        }
        
        $choice = (int)$choiceInput;
        
        if ($choice < 1 || $choice > count($matchedModules)) {
            $this->printer->error(__('无效选择：%{1}，请重新选择', [$choiceInput]));
            return $this->searchModules();
        }
        
        $selectedModule = $matchedModules[$choice - 1];
        $this->printer->success(__('已选择模块：%{1}', [$selectedModule['name']]));
        return $selectedModule['name'];
    }

    /**
     * 搜索模块用于完整性检测
     */
    private function searchModulesForCheck(): ?string
    {
        $this->printer->note(__('请输入要检测的模块名称（支持模糊搜索）：'));
        $search = trim($this->system->input());
        
        if (empty($search)) {
            $this->printer->warning(__('搜索关键词不能为空'));
            return $this->searchModulesForCheck();
        }
        
        // 获取所有模块
        $allModules = $this->getAllModules();
        $matchedModules = $this->filterModules($allModules, $search);
        
        if (empty($matchedModules)) {
            $this->printer->warning(__('未找到匹配的模块'));
            $this->printer->note(__('是否重新搜索？(y/n，默认：n)'));
            $retry = trim(strtolower($this->system->input()));
            if ($retry === 'y' || $retry === 'yes') {
                return $this->searchModulesForCheck();
            }
            return null;
        }
        
        // 如果只有一个匹配，直接使用
        if (count($matchedModules) === 1) {
            return $matchedModules[0]['name'];
        }
        
        // 显示匹配的模块列表
        $this->printer->printing("\n");
        $this->printer->note(__('找到以下匹配的模块：'));
        $index = 1;
        foreach ($matchedModules as $module) {
            $moduleName = $this->colorizeBold($module['name']);
            $relativePath = str_replace(BP, '', $module['path']);
            $relativePath = ltrim(str_replace('\\', DS, $relativePath), DS);
            
            // 路径着色
            if (strpos($relativePath, 'app' . DS . 'code') === 0) {
                $coloredPath = $this->colorizeText($relativePath, 'Blue');
            } elseif (strpos($relativePath, 'vendor') === 0) {
                $coloredPath = $this->colorizeText($relativePath, 'Purple');
            } else {
                $coloredPath = $relativePath;
            }
            
            $this->printer->printing("  {$index}. {$moduleName} ({$coloredPath})\n");
            $index++;
        }
        
        $this->printer->printing("\n");
        $this->printer->note(__('请选择要检测的模块（输入序号，或输入 0 返回）'));
        $choice = (int)trim($this->system->input());
        
        if ($choice === 0) {
            return null;
        }
        
        if ($choice < 1 || $choice > count($matchedModules)) {
            $this->printer->error(__('无效选择'));
            return $this->searchModulesForCheck();
        }
        
        $selectedModule = $matchedModules[$choice - 1];
        return $selectedModule['name'];
    }

    /**
     * 输入自定义模块名
     */
    private function inputCustomModuleName(): ?string
    {
        $this->printer->note(__('请输入模块名称（格式：Vendor_ModuleName，例如：Weline_Demo）'));
        $moduleName = trim($this->system->input());
        
        if (empty($moduleName)) {
            $this->printer->warning(__('模块名称不能为空'));
            $this->printer->note(__('是否重新输入？(y/n，默认：n)'));
            $retry = trim(strtolower($this->system->input()));
            if ($retry === 'y' || $retry === 'yes') {
                return $this->inputCustomModuleName();
            }
            return null;
        }
        
        // 验证模块名格式
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $moduleName)) {
            $this->printer->error(__('模块名称格式不正确，应为 Vendor_ModuleName 格式'));
            $this->printer->note(__('是否重新输入？(y/n，默认：n)'));
            $retry = trim(strtolower($this->system->input()));
            if ($retry === 'y' || $retry === 'yes') {
                return $this->inputCustomModuleName();
            }
            return null;
        }
        
        return $moduleName;
    }

    /**
     * 获取所有模块
     */
    private function getAllModules(): array
    {
        $modules = [];
        
        try {
            // 方式1：从 Env 获取已注册的模块
            if (class_exists('Weline\Framework\App\Env')) {
                $env = \Weline\Framework\App\Env::getInstance();
                $moduleList = $env->getModuleList();
                
                foreach ($moduleList as $moduleName => $module) {
                    $basePath = $module['base_path'] ?? '';
                    if (!empty($basePath) && is_dir($basePath)) {
                        $modules[] = [
                            'name' => $moduleName,
                            'path' => $basePath
                        ];
                    }
                }
            }
            
            // 方式2：扫描 app/code 目录
            $appCodePath = APP_CODE_PATH;
            if (is_dir($appCodePath)) {
                $dirIterator = new \RecursiveDirectoryIterator($appCodePath, \RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);
                
                foreach ($iterator as $file) {
                    // 使用 RecursiveIteratorIterator 的 getDepth() 方法
                    $depth = $iterator->getDepth();
                    if ($file->isDir() && $depth === 1) {
                        $modulePath = $file->getPathname();
                        $pathParts = explode(DS, str_replace($appCodePath, '', $modulePath));
                        $pathParts = array_filter($pathParts);
                        $pathParts = array_values($pathParts);
                        
                        if (count($pathParts) === 2) {
                            $moduleName = $pathParts[0] . '_' . $pathParts[1];
                            
                            // 避免重复
                            $exists = false;
                            foreach ($modules as $existingModule) {
                                if ($existingModule['name'] === $moduleName) {
                                    $exists = true;
                                    break;
                                }
                            }
                            
                            if (!$exists) {
                                $modules[] = [
                                    'name' => $moduleName,
                                    'path' => $modulePath
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('获取模块列表时出错：%{1}', [$e->getMessage()]));
        }
        
        // 按模块名排序
        usort($modules, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $modules;
    }

    /**
     * 过滤模块（根据搜索关键词）
     */
    private function filterModules(array $modules, string $search): array
    {
        if (empty($search)) {
            return $modules; // 如果搜索关键词为空，返回所有模块
        }
        
        $searchLower = strtolower(trim($search));
        $matched = [];
        
        foreach ($modules as $module) {
            $moduleName = isset($module['name']) ? strtolower($module['name']) : '';
            
            // 只匹配模块名称，不匹配完整路径（避免绝对路径中包含搜索词导致误匹配）
            // 例如：路径 E:\WelineFramework\... 中包含 "framework"，会误匹配 "frame"
            if (!empty($moduleName) && strpos($moduleName, $searchLower) !== false) {
                $matched[] = $module;
                continue;
            }
            
            // 如果模块名不匹配，尝试匹配相对路径（相对于项目根目录）
            if (isset($module['path']) && !empty($module['path'])) {
                // 获取相对路径（相对于项目根目录）
                $relativePath = str_replace(BP, '', $module['path']);
                $relativePath = ltrim(str_replace(['\\', DS], '/', $relativePath), '/');
                $relativePathLower = strtolower($relativePath);
                
                // 只匹配相对路径，不匹配绝对路径
                if (strpos($relativePathLower, $searchLower) !== false) {
                    $matched[] = $module;
                }
            }
        }
        
        return $matched;
    }

    /**
     * 检测模块完整性
     */
    private function checkModuleIntegrity(string $moduleName, string $modulePath): void
    {
        $this->printer->setup(__('=== 模块完整性检测 ==='));
        $this->printer->printing("\n");
        $this->printer->note(__('正在检测模块：%{1}', [$moduleName]));
        $this->printer->printing("\n");
        
        $issues = [];
        $warnings = [];
        $info = [];
        
        // 1. 检查模块目录是否存在
        if (!is_dir($modulePath)) {
            $issues[] = __('模块目录不存在：%{1}', [$modulePath]);
            $this->printer->error(__('模块目录不存在'));
            return;
        }
        $info[] = __('✓ 模块目录存在');
        
        // 2. 检查 register.php 文件
        $registerFile = $modulePath . DS . 'register.php';
        if (!file_exists($registerFile)) {
            $issues[] = __('缺少必需文件：register.php');
        } else {
            $info[] = __('✓ register.php 存在');
            // 检查 register.php 内容
            $registerContent = file_get_contents($registerFile);
            if (strpos($registerContent, 'Register::register') === false) {
                $warnings[] = __('register.php 中未找到 Register::register() 调用');
            }
            if (strpos($registerContent, $moduleName) === false) {
                $warnings[] = __('register.php 中未找到模块名：%{1}', [$moduleName]);
            }
            // 检查是否有 use 语句
            if (strpos($registerContent, 'use Weline\\Framework\\Register\\Register') === false && 
                strpos($registerContent, 'use Weline\\\\Framework\\\\Register\\\\Register') === false) {
                $warnings[] = __('register.php 中缺少 use Weline\\Framework\\Register\\Register 语句');
            }
        }
        
        // 3. 检查 etc/env.php 文件（可选但推荐）
        $envFile = $modulePath . DS . 'etc' . DS . 'env.php';
        if (!file_exists($envFile)) {
            $warnings[] = __('缺少推荐文件：etc/env.php（用于配置路由前缀）');
        } else {
            $info[] = __('✓ etc/env.php 存在');
            // 检查 env.php 内容
            $envContent = file_get_contents($envFile);
            if (strpos($envContent, 'router') === false) {
                $warnings[] = __('etc/env.php 中未找到 router 配置');
            }
        }
        
        // 4. 检查 Controller 目录和文件
        $controllerDir = $modulePath . DS . 'Controller';
        if (!is_dir($controllerDir)) {
            $warnings[] = __('缺少 Controller 目录');
        } else {
            $info[] = __('✓ Controller 目录存在');
            // 检查是否有控制器文件
            $controllerFiles = glob($controllerDir . DS . '**' . DS . '*.php');
            if (empty($controllerFiles)) {
                $warnings[] = __('Controller 目录为空，没有控制器文件');
            } else {
                $info[] = __('✓ 找到 %{1} 个控制器文件', [count($controllerFiles)]);
                // 检查控制器是否有对应的视图
                foreach ($controllerFiles as $controllerFile) {
                    $this->checkControllerView($controllerFile, $modulePath, $warnings);
                }
            }
        }
        
        // 5. 检查 view 目录结构
        $viewDir = $modulePath . DS . 'view';
        if (!is_dir($viewDir)) {
            $warnings[] = __('缺少 view 目录');
        } else {
            $info[] = __('✓ view 目录存在');
            // 检查 view/templates 目录
            $templatesDir = $viewDir . DS . 'templates';
            if (!is_dir($templatesDir)) {
                $warnings[] = __('缺少 view/templates 目录');
            } else {
                $info[] = __('✓ view/templates 目录存在');
            }
        }
        
        // 6. 检查 etc 目录
        $etcDir = $modulePath . DS . 'etc';
        if (!is_dir($etcDir)) {
            $warnings[] = __('缺少 etc 目录');
        } else {
            $info[] = __('✓ etc 目录存在');
        }
        
        // 7. 检查模块配置文件（.module_config.json）
        $configFile = $modulePath . DS . '.module_config.json';
        if (file_exists($configFile)) {
            $info[] = __('✓ 找到模块配置文件：.module_config.json');
        }
        
        // 8. 检查是否有空的目录（可能未使用）
        $emptyDirs = $this->findEmptyDirectories($modulePath);
        if (!empty($emptyDirs)) {
            foreach ($emptyDirs as $emptyDir) {
                $relativePath = str_replace($modulePath, '', $emptyDir);
                $warnings[] = __('空目录（可能未使用）：%{1}', [$relativePath]);
            }
        }
        
        // 显示检测结果
        $this->printer->printing("\n");
        $this->printer->note(__('=== 检测结果 ==='));
        
        // 显示信息
        if (!empty($info)) {
            $this->printer->printing("\n");
            $this->printer->success(__('正常项（%{1} 项）：', [count($info)]));
            foreach ($info as $item) {
                $this->printer->printing("  {$item}\n");
            }
        }
        
        // 显示警告
        if (!empty($warnings)) {
            $this->printer->printing("\n");
            $this->printer->warning(__('警告项（%{1} 项）：', [count($warnings)]));
            foreach ($warnings as $warning) {
                $this->printer->printing("  ⚠ {$warning}\n");
            }
        }
        
        // 显示错误
        if (!empty($issues)) {
            $this->printer->printing("\n");
            $this->printer->error(__('错误项（%{1} 项）：', [count($issues)]));
            foreach ($issues as $issue) {
                $this->printer->printing("  ✗ {$issue}\n");
            }
        }
        
        // 总结
        $this->printer->printing("\n");
        if (empty($issues) && empty($warnings)) {
            $this->printer->success(__('模块完整性检测通过！所有检查项都正常。'));
        } elseif (empty($issues)) {
            $this->printer->note(__('模块基本完整，但有 %{1} 个警告项需要注意', [count($warnings)]));
        } else {
            $this->printer->error(__('模块存在 %{1} 个错误和 %{2} 个警告', [count($issues), count($warnings)]));
        }
    }

    /**
     * 检查控制器是否有对应的视图
     */
    private function checkControllerView(string $controllerFile, string $modulePath, array &$warnings): void
    {
        $content = file_get_contents($controllerFile);
        
        // 提取命名空间和类名
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
            $namespace = $nsMatches[1];
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = $classMatches[1];
                
                // 判断控制器类型
                $viewArea = 'Frontend';
                if (strpos($namespace, 'Backend') !== false) {
                    $viewArea = 'Backend';
                }
                
                // 检查是否是API控制器（不需要视图）
                if (strpos($namespace, 'RestController') !== false || 
                    strpos($namespace, 'Api') !== false) {
                    return; // API控制器不需要视图，跳过检查
                }
                
                // 检查是否有对应的视图文件
                // 尝试多种可能的视图路径
                $possibleViewPaths = [
                    $modulePath . DS . 'view' . DS . 'templates' . DS . $viewArea . DS . $className,
                    $modulePath . DS . 'view' . DS . 'templates' . DS . $viewArea . DS . strtolower($className),
                ];
                
                $viewFound = false;
                foreach ($possibleViewPaths as $viewPath) {
                    if (is_dir($viewPath)) {
                        $viewFound = true;
                        // 检查视图目录中是否有 index.phtml
                        $indexView = $viewPath . DS . 'index.phtml';
                        if (!file_exists($indexView)) {
                            $warnings[] = __('控制器 %{1} 的视图目录存在，但缺少 index.phtml 文件', [$className]);
                        }
                        break;
                    }
                }
                
                if (!$viewFound) {
                    // 检查是否有任何视图文件（可能使用了不同的命名）
                    $viewBasePath = $modulePath . DS . 'view' . DS . 'templates' . DS . $viewArea;
                    if (is_dir($viewBasePath)) {
                        // 检查是否有任何匹配的视图目录
                        $viewDirs = glob($viewBasePath . DS . '*', GLOB_ONLYDIR);
                        $hasAnyView = false;
                        foreach ($viewDirs as $viewDir) {
                            $viewFiles = glob($viewDir . DS . '*.phtml');
                            if (!empty($viewFiles)) {
                                $hasAnyView = true;
                                break;
                            }
                        }
                        
                        if (!$hasAnyView) {
                            $warnings[] = __('控制器 %{1} 没有对应的视图目录：%{2}', [
                                $className,
                                str_replace($modulePath, '', $possibleViewPaths[0])
                            ]);
                        }
                    } else {
                        $warnings[] = __('控制器 %{1} 没有对应的视图目录：%{2}', [
                            $className,
                            str_replace($modulePath, '', $possibleViewPaths[0])
                        ]);
                    }
                }
            }
        }
    }

    /**
     * 查找空目录
     */
    private function findEmptyDirectories(string $dir): array
    {
        $emptyDirs = [];
        
        if (!is_dir($dir)) {
            return $emptyDirs;
        }
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    $dirPath = $file->getPathname();
                    // 检查目录是否为空（排除 .git, .svn 等隐藏目录）
                    $files = array_filter(scandir($dirPath), function($item) {
                        return !in_array($item, ['.', '..', '.git', '.svn', '.DS_Store']);
                    });
                    
                    if (empty($files)) {
                        $emptyDirs[] = $dirPath;
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        return $emptyDirs;
    }
}

