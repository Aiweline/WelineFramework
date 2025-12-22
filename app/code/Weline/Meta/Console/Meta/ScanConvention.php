<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Console\Meta;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Meta\Model\Meta;

class ScanConvention extends CommandAbstract
{
    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? $args['m'] ?? '';
        $verbose = isset($args['verbose']) || isset($args['v']);

        // 获取模块模型
        /** @var Module $moduleModel */
        $moduleModel = ObjectManager::getInstance(Module::class);

        // 确定要扫描的模块列表
        $modulesToScan = [];
        $moduleList = Env::getInstance()->getModuleList();
        
        if (!empty($moduleName)) {
            // 指定了模块，只扫描该模块
            if (!isset($moduleList[$moduleName])) {
                $this->printer->error(__('模块不存在：%{1}', [$moduleName]));
                return;
            }
            $module = ObjectManager::getInstance(Module::class);
            $module->setData($moduleList[$moduleName]);
            $modulesToScan[] = $module;
        } else {
            // 没有指定模块，使用 ExtendsData 获取所有扩展了 Meta 模块的模块
            $this->printer->note(__('未指定模块，将扫描所有扩展了 Meta 模块的模块...'));
            
            // 使用 ExtendsData 获取所有扩展了 Weline_Meta 的模块
            $extendedByMeta = [];
            if (class_exists(ExtendsData::class)) {
                try {
                    $extendedByMeta = ExtendsData::getExtendedBy('Weline_Meta');
                    if (!empty($extendedByMeta)) {
                        $this->printer->note(__('通过 ExtendsData 找到 %{1} 个扩展了 Meta 模块的模块', [count($extendedByMeta)]));
                    }
                } catch (\Exception $e) {
                    $this->printer->warning(__('无法从 ExtendsData 获取模块列表，将回退到扫描所有模块: %{1}', [$e->getMessage()]));
                }
            }
            
            // 如果 ExtendsData 返回了结果，只扫描这些模块
            if (!empty($extendedByMeta)) {
                $uniqueModules = [];
                foreach ($extendedByMeta as $extendingModuleName => $extensionInfo) {
                    // 检查模块是否在模块列表中
                    if (!isset($moduleList[$extendingModuleName])) {
                        continue;
                    }
                    
                    $moduleData = $moduleList[$extendingModuleName];
                    if (!is_array($moduleData)) {
                        continue;
                    }
                    
                    // 创建 Module 对象
                    $module = new Module();
                    $module->setData($moduleData);
                    // 确保模块的 name 字段正确设置
                    if (empty($module->getData('name')) || $module->getData('name') !== $extendingModuleName) {
                        $module->setData('name', $extendingModuleName);
                    }
                    $uniqueModules[$extendingModuleName] = $module;
                }
                // 转换为数组（确保去重）
                $modulesToScan = array_values($uniqueModules);
                $this->printer->note(__('共找到 %{1} 个需要扫描的模块', [count($modulesToScan)]));
            } else {
                // 回退方案：扫描所有模块（兼容性处理）
                $this->printer->note(__('未找到扩展 Meta 模块的模块，将扫描所有模块...'));
                $uniqueModules = [];
                foreach ($moduleList as $moduleName => $moduleData) {
                    // 确保模块数据是数组
                    if (!is_array($moduleData)) {
                        continue;
                    }
                    
                    // 使用数组键作为唯一标识（模块列表的键应该是唯一的模块名）
                    // 优先使用数组键，如果数组键为空，才使用模块数据中的name
                    $actualModuleName = !empty($moduleName) ? $moduleName : ($moduleData['name'] ?? '');
                    if (empty($actualModuleName)) {
                        continue; // 跳过没有名称的模块
                    }
                    
                    // 如果已经处理过这个模块（使用数组键去重），跳过
                    if (isset($uniqueModules[$actualModuleName])) {
                        continue;
                    }
                    
                    // 使用 make() 创建新实例，避免单例问题
                    $module = new Module();
                    $module->setData($moduleData);
                    // 确保模块的 name 字段正确设置（使用数组键）
                    if (empty($module->getData('name')) || $module->getData('name') !== $actualModuleName) {
                        $module->setData('name', $actualModuleName);
                    }
                    $uniqueModules[$actualModuleName] = $module;
                }
                // 转换为数组（确保去重）
                $modulesToScan = array_values($uniqueModules);
                $this->printer->note(__('共找到 %{1} 个唯一模块', [count($modulesToScan)]));
            }
        }

        $totalResults = [
            'stored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
            'errors' => []
        ];

        // 遍历所有要扫描的模块（再次去重，防止重复）
        $processedModules = [];
        $moduleCount = count($modulesToScan);
        $this->printer->note(__('准备扫描 %{1} 个模块...', [$moduleCount]));
        
        foreach ($modulesToScan as $index => $module) {
            try {
                $currentModuleName = $module->getData('name');
                
                // 如果模块名为空，跳过
                if (empty($currentModuleName)) {
                    if ($verbose) {
                        $this->printer->note(__('跳过模块（索引 %{1}）：模块名为空', [$index]));
                    }
                    continue;
                }
                
                // 如果已经处理过，跳过
                if (isset($processedModules[$currentModuleName])) {
                    if ($verbose) {
                        $this->printer->note(__('跳过模块 %{1}：已处理过', [$currentModuleName]));
                    }
                    continue;
                }
                $processedModules[$currentModuleName] = true;
                
                $this->printer->note("\n" . str_repeat('=', 60));
                $this->printer->note(__('开始扫描模块 %{1} 的 @meta.json 规约文件...', [$currentModuleName]));
            } catch (\Exception $e) {
                $this->printer->error(__('处理模块（索引 %{1}）时出错：%{2}', [$index, $e->getMessage()]));
                continue;
            }

            try {
                // 获取模块路径，优先使用 base_path
                $basePath = $module->getData('base_path');
                if (empty($basePath)) {
                    // 如果没有 base_path，尝试使用 path
                    $modulePath = $module->getData('path');
                    if (empty($modulePath)) {
                        $this->printer->error(__('模块 %{1} 没有路径信息', [$currentModuleName]));
                        $totalResults['failed']++;
                        continue;
                    }
                } else {
                    $modulePath = $basePath;
                }
                
                // 统一路径分隔符
                $modulePath = str_replace('\\', '/', $modulePath);
                
                // 如果是相对路径，需要加上 BP
                if (!is_dir($modulePath) && !file_exists($modulePath)) {
                    $modulePath = BP . '/' . trim($modulePath, '/');
                }
                
                // 确保路径是绝对路径
                if (!is_dir($modulePath)) {
                    $this->printer->error(__('模块 %{1} 路径不存在：%{2}', [$currentModuleName, $modulePath]));
                    $totalResults['failed']++;
                    continue;
                }
                // 统一路径分隔符
                $modulePath = str_replace('\\', '/', $modulePath);
                $conventionPath = rtrim($modulePath, '/') . '/extends/Weline_Meta/' . $currentModuleName . '/@meta.json';
                // 统一路径分隔符（Windows 兼容）
                $conventionPath = str_replace('/', DIRECTORY_SEPARATOR, $conventionPath);

                if (!file_exists($conventionPath)) {
                    if ($verbose) {
                        $this->printer->note(__('模块 %{1} 规约文件不存在：%{2}', [$currentModuleName, $conventionPath]));
                    } else {
                        $this->printer->note(__('模块 %{1} 没有规约文件，跳过', [$currentModuleName]));
                    }
                    $totalResults['skipped']++;
                    continue;
                }

                $this->printer->success(__('找到规约文件：%{1}', [$conventionPath]));

                // 读取规约文件
                $conventionContent = file_get_contents($conventionPath);
                $convention = json_decode($conventionContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->printer->error(__('模块 %{1} 规约文件 JSON 格式错误：%{2}', [$currentModuleName, json_last_error_msg()]));
                    $totalResults['failed']++;
                    continue;
                }

                if (!isset($convention['meta'])) {
                    $this->printer->error(__('模块 %{1} 规约文件格式错误：缺少 \'meta\' 字段', [$currentModuleName]));
                    $totalResults['failed']++;
                    continue;
                }

                $metaConvention = $convention['meta'];
                $basePath = $metaConvention['base_path'] ?? '';

                if (empty($basePath)) {
                    $this->printer->error(__('模块 %{1} 规约文件中缺少 \'base_path\' 字段', [$currentModuleName]));
                    $totalResults['failed']++;
                    continue;
                }

                $this->printer->note(__('基础路径：%{1}', [$basePath]));

                // 解析 base_path
                if (strpos($basePath, '::') === false) {
                    $this->printer->error(__('模块 %{1} base_path 格式错误，应为：ModuleName::相对路径', [$currentModuleName]));
                    $totalResults['failed']++;
                    continue;
                }

                [$baseModuleName, $relativePath] = explode('::', $basePath, 2);
                
                // 获取基础模块路径
                $moduleList = Env::getInstance()->getModuleList();
                if (!isset($moduleList[$baseModuleName])) {
                    $this->printer->error(__('模块 %{1} 基础模块不存在：%{2}', [$currentModuleName, $baseModuleName]));
                    $totalResults['failed']++;
                    continue;
                }
                $baseModule = ObjectManager::getInstance(Module::class);
                $baseModule->setData($moduleList[$baseModuleName]);

                // 获取基础模块路径
                $baseModulePath = $baseModule->getData('base_path');
                if (empty($baseModulePath)) {
                    $baseModulePath = $baseModule->getData('path');
                }
                // 统一路径分隔符
                $baseModulePath = str_replace('\\', '/', $baseModulePath);
                // 如果是相对路径，需要加上 BP
                if (!is_dir($baseModulePath) && !file_exists($baseModulePath)) {
                    $baseModulePath = BP . '/' . trim($baseModulePath, '/');
                }
                $scanPath = rtrim($baseModulePath, '/') . '/' . ltrim($relativePath, '/');

                if (!is_dir($scanPath)) {
                    $this->printer->error(__('模块 %{1} 扫描路径不存在：%{2}', [$currentModuleName, $scanPath]));
                    $totalResults['failed']++;
                    continue;
                }

                $this->printer->note(__('扫描路径：%{1}', [$scanPath]));

                // 从规约中提取命名空间
                $namespace = null;
                foreach ($metaConvention as $key => $value) {
                    if ($key !== 'base_path') {
                        $namespace = $key;
                        break;
                    }
                }

                // 如果没有定义命名空间，从 base_path 的路径中提取最后一个目录名
                if (!$namespace) {
                    $pathParts = explode('/', trim($relativePath, '/'));
                    $namespace = end($pathParts); // 获取最后一个目录名
                    if (empty($namespace)) {
                        // 如果路径为空，尝试从完整路径中提取
                        $fullPathParts = explode('/', trim($scanPath, '/'));
                        $namespace = end($fullPathParts);
                    }
                    
                    if (empty($namespace)) {
                        $this->printer->error(__('模块 %{1} 无法从 base_path 中提取命名空间', [$currentModuleName]));
                        $totalResults['failed']++;
                        continue;
                    }
                    
                    $this->printer->note(__('从 base_path 提取命名空间：%{1}', [$namespace]));
                } else {
                    $this->printer->note(__('命名空间：%{1}', [$namespace]));
                }

                // 扫描目录结构并存储元数据（只根据目录结构，不依赖规约中的默认值等）
                // 同时验证文件中的 @meta:: 标记是否与目录层级匹配
                $results = $this->scanAndStore($namespace, $scanPath, $basePath, $metaConvention, $currentModuleName, $verbose);

                // 累计结果
                $totalResults['stored'] += $results['stored'];
                $totalResults['skipped'] += $results['skipped'];
                $totalResults['failed'] += $results['failed'];
                $totalResults['details'] = array_merge($totalResults['details'], $results['details']);
                $totalResults['errors'] = array_merge($totalResults['errors'], $results['errors']);

                $this->printer->success(__('模块 %{1} 扫描完成：成功 %{2}，失败 %{3}', [$currentModuleName, $results['stored'], $results['failed']]));

            } catch (\Exception $e) {
                // 输出详细的错误信息
                $this->printer->error("\n" . str_repeat('=', 60));
                $this->printer->error(__('【扫描失败】模块：%{1}', [$currentModuleName]));
                $this->printer->error(str_repeat('-', 60));
                $this->printer->error($e->getMessage());
                $this->printer->error(str_repeat('=', 60) . "\n");
                
                $totalResults['failed']++;
                if ($verbose) {
                    $this->printer->note(__('堆栈跟踪：'));
                    $this->printer->printing($e->getTraceAsString() . "\n");
                }
                // 验证失败不允许跳过，直接抛出异常停止扫描
                throw $e;
            }
        }

        // 显示总体结果
        $this->printer->success("\n" . str_repeat('=', 60));
        $this->printer->success(__('所有模块扫描完成！'));
        $this->printer->note(__('成功存储：%{1} 条元数据', [$totalResults['stored']]));
        $this->printer->note(__('跳过：%{1} 个模块', [$totalResults['skipped']]));
        
        if ($totalResults['failed'] > 0) {
            $this->printer->error(__('失败：%{1} 个文件验证失败', [$totalResults['failed']]));
            
            foreach ($totalResults['errors'] as $errorInfo) {
                $this->printer->error(__('文件：%{1}', [$errorInfo['file']]));
                foreach ($errorInfo['errors'] as $error) {
                    $this->printer->printing("  - {$error}\n");
                }
            }
        }
        
        if ($verbose && !empty($totalResults['details'])) {
            $this->printer->note(__("\n详细信息："));
            foreach ($totalResults['details'] as $detail) {
                $this->printer->printing("  - {$detail}\n");
            }
        }
    }

    /**
     * 扫描目录结构并存储元数据（只根据目录结构，不依赖规约中的默认值等）
     * 所有目录路径部分都是 group，只有扫描到具体字段时才是 field
     * 同时验证文件中的 @meta:: 标记是否与目录层级匹配
     */
    protected function scanAndStore(string $namespace, string $scanPath, string $basePath, array $convention, string $moduleName, bool $verbose): array
    {
        $results = [
            'stored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
            'errors' => []
        ];

        // 递归扫描目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var Meta $metaModel */
        $metaModel = ObjectManager::make(Meta::class);

        // 存储已扫描的目录结构（用于构建 group 层级）
        $scannedGroups = [];
        
        // 规范化扫描路径（用于计算相对路径）
        $scanPathNormalized = rtrim(str_replace('\\', '/', $scanPath), '/');

        foreach ($iterator as $file) {
            // 只处理文件，跳过目录
            if (!$file->isFile()) {
                continue;
            }
            
            // 支持扫描 .phtml 和 .css 文件
            // CSS 文件只扫描 colors 和 variables 目录下的（用于色系和变量配置）
            // 排除 assets/css 目录下的 CSS 文件（这些是样式文件，不是配置）
            $extension = strtolower($file->getExtension());
            if ($extension !== 'phtml' && $extension !== 'css') {
                continue;
            }
            
                $filePath = $file->getPathname();
                $filePathNormalized = str_replace('\\', '/', $filePath);
                $relativePath = str_replace($scanPathNormalized . '/', '', $filePathNormalized);
            
            // 对于 CSS 文件，只扫描 colors 和 variables 目录下的
            if ($extension === 'css') {
                // 检查路径中是否包含 colors 或 variables 目录
                $tempPathParts = explode('/', $relativePath);
                $hasColorsOrVariables = false;
                foreach ($tempPathParts as $part) {
                    if ($part === 'colors' || $part === 'variables') {
                        $hasColorsOrVariables = true;
                        break;
                    }
                }
                // 如果不在 colors 或 variables 目录下，跳过这个 CSS 文件
                if (!$hasColorsOrVariables) {
                    continue;
                }
            }
                
                // 构建路径部分（只包含相对路径部分，不包含完整绝对路径）
                $pathParts = explode('/', $relativePath);
                $pathParts = array_filter($pathParts, function($part) {
                    return $part !== '' && $part !== '.' && $part !== '..';
                });
                $pathParts = array_values($pathParts); // 重新索引数组
                
                // 移除文件名，只保留目录结构（这些都是 group）
                $fileName = array_pop($pathParts);
                $fileNameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
                
                // 构建 meta key：namespace.group1.group2.group3...
                // 所有路径部分都是 group
                $metaKeyParts = [$namespace];
                foreach ($pathParts as $part) {
                    $metaKeyParts[] = $part;
                }
                $metaKey = implode('.', $metaKeyParts);
                
                // 读取文件内容，提取所有标记
                $fileContent = file_get_contents($filePath);
                
                // 收集 @meta.xxx 格式的字段定义（简化格式，目录结构由文件路径确定）
                $metaFields = $this->collectMetaFields($fileContent, $metaKey, $namespace, $convention);
                
            // 验证字段是否在规约中允许（如果文件没有 meta 字段，仍然允许存储 group 信息）
            if (!empty($metaFields)) {
                $validationResult = $this->validateMetaFields($metaFields, $filePath, $metaKey, $namespace, $convention);
                
                if (!$validationResult['valid']) {
                    // 构建详细的错误信息
                    $errorDetails = [];
                    $errorDetails[] = __('【文件验证失败】');
                    $errorDetails[] = __('文件路径：%{1}', [$filePath]);
                    $errorDetails[] = __('Meta Key：%{1}', [$metaKey]);
                    $errorDetails[] = __('命名空间：%{1}', [$namespace]);
                    $errorDetails[] = '';
                    $errorDetails[] = __('验证错误详情：');
                    
                    foreach ($validationResult['errors'] as $index => $error) {
                        $errorDetails[] = __('  %{1}. %{2}', [$index + 1, $error]);
                    }
                    
                    $errorDetails[] = '';
                    $errorDetails[] = __('解决方案：');
                    $errorDetails[] = __('  1. 检查 @meta.json 规约文件，确保定义了对应的 meta key');
                    $errorDetails[] = __('  2. 或者从文件中移除未定义的 @meta.xxx 字段');
                    $errorDetails[] = __('  3. 或者更新规约文件，添加缺失的 meta key 定义');
                    
                    if ($verbose) {
                        foreach ($validationResult['errors'] as $error) {
                            $results['details'][] = __('错误：%{1} - %{2}', [$filePath, $error]);
                        }
                    }
                    
                    // 验证失败不允许跳过，直接抛出异常
                    $errorMessage = implode("\n", $errorDetails);
                    throw new \Exception($errorMessage);
                }
                }
                
                // 收集其他 @ 标记（如 @param.xxx, @preview.login 等）
                $collectedData = $this->collectOtherTags($fileContent, $fileNameWithoutExt);
                $otherTags = $collectedData['tags'] ?? [];
                $setting = $collectedData['setting'] ?? [];
                
                // 存储目录结构信息（作为 group），包含收集的其他标记和设置
                $this->storeGroupMetaData($metaModel, $namespace, $metaKey, $filePath, $pathParts, $fileNameWithoutExt, $moduleName, $scanPathNormalized, $otherTags, $setting, $verbose);
                
                // 为每个 @meta.xxx 字段单独存储一条记录
                foreach ($metaFields as $fieldName => $fieldData) {
                    $this->storeMetaField($metaModel, $namespace, $fieldData['full_meta_key'], $filePath, $pathParts, $fileNameWithoutExt, $moduleName, $scanPathNormalized, $fieldName, $fieldData['attributes'], $verbose);
                    $results['stored']++;
                    
                    if ($verbose) {
                        $results['details'][] = __('存储 Meta 字段：%{1} -> %{2}', [$fieldData['full_meta_key'], $filePath]);
                    }
                }
                
                // 记录已扫描的 group
                $groupKey = implode('.', $metaKeyParts);
                if (!isset($scannedGroups[$groupKey])) {
                    $scannedGroups[$groupKey] = true;
                    $results['stored']++;
                    
                    if ($verbose) {
                        $results['details'][] = __('存储 Group：%{1} -> %{2}', [$groupKey, $filePath]);
                }
            }
        }

        return $results;
    }

    /**
     * 收集其他 @ 标记（如 @param.xxx, @preview.login 等）
     * 返回两个数组：$tags（其他标记）和 $setting（参数设置）
     */
    protected function collectOtherTags(string $content, string $fileName): array
    {
        $tags = [];
        $setting = [];
        
        // 收集 @param.xxx 或 @param xxx 格式的参数标记（存储到 setting 中）
        // 支持两种格式：
        // 1. @param.title {default="",name="",description=""} （带点）
        // 2. @param title {default="",name="",description=""} （不带点，空格分隔）
            $params = [];
        
        // 匹配 @param.xxx 格式（带点）
        if (preg_match_all('/@param\.(\w+(?:\.\w+)*)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $paramName = trim($match[1]); // 如：title, sidebar.collapsed
                $attributesStr = trim($match[2]);
                $attributes = $this->parseAttributes($attributesStr);
                
                // 构建嵌套结构（支持 param.title 或 param.sidebar.collapsed）
                $paramParts = explode('.', $paramName);
                $current = &$params;
                foreach ($paramParts as $part) {
                    if (!isset($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
                // 合并属性到最终节点
                $current = array_merge($current, $attributes);
            }
        }
        
        // 匹配 @param xxx 格式（不带点，空格分隔）
        if (preg_match_all('/@param\s+(\w+(?:\.\w+)*)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $paramName = trim($match[1]); // 如：title, showHeader
                $attributesStr = trim($match[2]);
                $attributes = $this->parseAttributes($attributesStr);
                
                // 对于不带点的格式，直接使用参数名作为键（扁平结构）
                // 如果参数名包含点，则构建嵌套结构
                if (strpos($paramName, '.') !== false) {
                    $paramParts = explode('.', $paramName);
                    $current = &$params;
                    foreach ($paramParts as $part) {
                        if (!isset($current[$part])) {
                            $current[$part] = [];
                        }
                        $current = &$current[$part];
                    }
                    // 合并属性到最终节点
                    $current = array_merge($current, $attributes);
                } else {
                    // 扁平结构，直接使用参数名作为键
                    if (!isset($params[$paramName])) {
                        $params[$paramName] = [];
                    }
                    $params[$paramName] = array_merge($params[$paramName], $attributes);
                }
            }
        }
        
            if (!empty($params)) {
                $setting['param'] = $params;
        }
        
        // 收集 @preview.login 标记
        if (preg_match('/@preview\.login\s*\{([^}]+)\}/i', $content, $matches)) {
            $attributes = $this->parseAttributes($matches[1]);
            $tags['preview_login'] = $attributes;
        }
        
        // 收集其他 @ 标记（除了 @meta::, @meta.xxx, @param.xxx, @param xxx）
        // 注意：正则表达式 @(\w+(?:\.\w+)*)\s*\{ 只会匹配 @xxx { 格式，不会匹配 @param xxx { 格式
        // 因为 @param xxx { 中，param 后面是空格和 xxx，不符合 \w+ 后面直接是 \s*\{ 的模式
        if (preg_match_all('/@(\w+(?:\.\w+)*)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tagName = trim($match[1]);
                // 跳过 @meta::, @meta.xxx, @param.xxx, @param 标记
                // 注意：@param xxx 格式已经在上面处理过了，不会被这个正则匹配到
                if (strpos($tagName, 'meta::') === 0 || 
                    strpos($tagName, 'meta.') === 0 || 
                    strpos($tagName, 'param.') === 0 ||
                    $tagName === 'param') {
                    continue;
                }
                
                $attributesStr = trim($match[2]);
                $attributes = $this->parseAttributes($attributesStr);
                
                // 直接使用标记名作为键（保持原始格式，如 preview.login）
                $tagKey = $tagName;
                
                // 如果已存在，转换为数组
                if (isset($tags[$tagKey])) {
                    if (!is_array($tags[$tagKey]) || !isset($tags[$tagKey][0])) {
                        $tags[$tagKey] = [$tags[$tagKey]];
                    }
                    $tags[$tagKey][] = $attributes;
                } else {
                    $tags[$tagKey] = $attributes;
                }
            }
        }
        
        return [
            'tags' => $tags,
            'setting' => $setting
        ];
    }

    /**
     * 解析属性字符串为数组
     */
    protected function parseAttributes(string $attributesStr): array
    {
        $attributes = [];
        
        // 先处理带引号的值，再处理不带引号的值
        $pattern = '/(\w+)=(?:"([^"]*)"|\{([^}]+)\}|([^,}]+))/';
        if (preg_match_all($pattern, $attributesStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim($match[1]);
                // 优先使用引号内的值，然后是花括号内的值，最后是不带引号的值
                $value = '';
                if (isset($match[2]) && $match[2] !== '') {
                    $value = trim($match[2]);
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $value = trim($match[3]);
                } elseif (isset($match[4]) && $match[4] !== '') {
                    $value = trim($match[4]);
                }
                
                // 处理特殊值
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                } elseif ($value === '[]') {
                    $value = [];
                }
                
                $attributes[$key] = $value;
            }
        }
        
        return $attributes;
    }

    /**
     * 收集 @meta.xxx 格式的字段定义（简化格式，目录结构由文件路径确定）
     * 
     * @param string $content 文件内容
     * @param string $baseMetaKey 基础 meta key（由目录结构确定，如 theme.frontend.layouts.default）
     * @param string $namespace 命名空间
     * @param array $convention 规约配置
     * @return array 字段数组，格式：['name' => {...attributes...}, 'description' => {...attributes...}]
     */
    protected function collectMetaFields(string $content, string $baseMetaKey, string $namespace, array $convention): array
    {
        $fields = [];
        
        // 提取所有 @meta.xxx 格式的标记（如 @meta.name, @meta.description）
        if (preg_match_all('/@meta\.(\w+(?:\.\w+)*)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fieldName = trim($match[1]); // 如：name, description
                $attributesStr = trim($match[2]);
                $attributes = $this->parseAttributes($attributesStr);
                
                // 构建完整的 meta key：baseMetaKey.fieldName
                $fullMetaKey = $baseMetaKey . '.' . $fieldName;
                
                $fields[$fieldName] = [
                    'full_meta_key' => $fullMetaKey,
                    'attributes' => $attributes
                ];
            }
        }
        
        return $fields;
    }
    
    /**
     * 验证 @meta.xxx 字段是否在规约中允许
     * 
     * @param array $metaFields 字段数组
     * @param string $filePath 文件路径
     * @param string $baseMetaKey 基础 meta key（由目录结构确定）
     * @param string $namespace 命名空间
     * @param array $convention 规约配置
     * @return array 验证结果
     */
    protected function validateMetaFields(array $metaFields, string $filePath, string $baseMetaKey, string $namespace, array $convention): array
    {
        $errors = [];
        
        foreach ($metaFields as $fieldName => $fieldData) {
            $fullMetaKey = $fieldData['full_meta_key'];
            
            // 验证完整的 meta key 是否在规约文件中定义
            if (!$this->isMetaKeyDefinedInConvention($fullMetaKey, $namespace, $convention)) {
                // 构建详细的错误信息
                $errorParts = [];
                $errorParts[] = __('字段名称：%{1}', [$fieldName]);
                $errorParts[] = __('完整 Meta Key：%{1}', [$fullMetaKey]);
                $errorParts[] = __('命名空间：%{1}', [$namespace]);
                $errorParts[] = __('错误原因：该 Meta Key 在规约文件（@meta.json）中未定义');
                $errorParts[] = __('说明：文件中的 @meta.%{1} 字段对应的完整路径 %{2} 在规约文件中找不到对应的定义', [$fieldName, $fullMetaKey]);
                
                $errors[] = implode("\n    ", $errorParts);
                continue;
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 检查 meta key 是否在规约文件中定义
     * 如果规约文件中只定义了 base_path，则允许所有目录层级
     * 如果规约文件中定义了其他层级，则必须匹配
     */
    protected function isMetaKeyDefinedInConvention(string $metaKey, string $namespace, array $convention): bool
    {
        // 移除 namespace 前缀
        $keyWithoutNamespace = str_replace($namespace . '.', '', $metaKey);
        if (empty($keyWithoutNamespace)) {
            return true; // 只有 namespace，允许
        }
        
        $keyParts = explode('.', $keyWithoutNamespace);
        
        // 检查规约中是否定义了 namespace
        if (!isset($convention[$namespace])) {
            // 如果规约中只有 base_path，允许所有目录层级
            $hasOnlyBasePath = count($convention) === 1 && isset($convention['base_path']);
            return $hasOnlyBasePath;
        }
        
        // 从规约中查找
        $current = $convention[$namespace];
        
        // 如果规约中只有 base_path，允许所有目录层级
        if (count($convention) === 1 && isset($convention['base_path'])) {
            return true;
        }
        
        // 标记是否已经允许所有子层级（当遇到 options 时设置）
        $allowAllChildren = false;
        
        // 遍历 key parts，检查是否在规约中定义
        foreach ($keyParts as $part) {
            // 如果已经允许所有子层级，直接通过
            if ($allowAllChildren) {
                continue;
            }
            
            if (is_array($current) && isset($current[$part])) {
                // 找到了对应的 part，继续向下查找
                $current = $current[$part];
                
                // 检查当前层级是否有 options，如果有，说明这是可选的子层级，允许所有子层级及其子层级
                if (is_array($current) && isset($current['options'])) {
                    // 允许所有子层级（因为规约文件中定义了父层级，且当前 part 在 options 中）
                    $allowAllChildren = true;
                    continue;
                }
                
                // 如果当前层级是数组，检查是否只有配置字段（如 default, name, description 等）
                // 如果是，说明这是字段定义，允许所有子层级
                if (is_array($current)) {
                    $configKeys = ['default', 'name', 'description', 'label', 'desc', 'options', 'values', 'type', 'required', 'placeholder', 'show'];
                    $hasOnlyConfig = true;
                    foreach (array_keys($current) as $key) {
                        if (!in_array($key, $configKeys)) {
                            $hasOnlyConfig = false;
                            break;
                        }
                    }
                    if ($hasOnlyConfig) {
                        // 只有配置字段，说明这是字段定义，允许所有子层级
                        $allowAllChildren = true;
                        continue;
                    }
                }
            } else {
                // 当前 part 不在 current 中
                // 如果当前层级有 options，检查当前 part 是否在 options 中
                if (is_array($current) && isset($current['options'])) {
                    // 检查当前 part 是否在 options 中
                    if (isset($current['options'][$part])) {
                        // 当前 part 在 options 中，允许所有子层级
                        $allowAllChildren = true;
                        continue;
                    }
                }
                
                // 如果当前层级是数组，检查是否只有配置字段（如 default, name, description 等）
                // 如果是，说明这是字段定义，允许所有子层级
                if (is_array($current)) {
                    $configKeys = ['default', 'name', 'description', 'label', 'desc', 'options', 'values', 'type', 'required', 'placeholder', 'show'];
                    $hasOnlyConfig = true;
                    foreach (array_keys($current) as $key) {
                        if (!in_array($key, $configKeys)) {
                            $hasOnlyConfig = false;
                            break;
                        }
                    }
                    if ($hasOnlyConfig) {
                        // 只有配置字段，说明这是字段定义，允许所有子层级
                        $allowAllChildren = true;
                        continue;
                    }
                }
                
                // 规约中定义了其他字段，但没有当前 part，返回 false
                return false;
            }
        }
        
        return true;
    }

    /**
     * 存储 Group 元数据到数据库（目录结构都是 group）
     */
    protected function storeGroupMetaData(Meta $metaModel, string $namespace, string $metaKey, string $filePath, array $pathParts, string $fileName, string $moduleName, string $scanPath, array $otherTags = [], array $setting = [], bool $verbose = false): void
    {
        // 确定类型和标识（根据路径结构推断）
        $type = 'group'; // 默认类型为 group
        $category = null;
        $area = null;

        // 尝试从路径中提取类型和区域
        $foundType = false;
        foreach ($pathParts as $part) {
            if ($part === 'layouts') {
                $type = 'layout';
                $foundType = true;
            } elseif ($part === 'components') {
                $type = 'component';
                $foundType = true;
            } elseif ($part === 'partials') {
                $type = 'partial';
                $foundType = true;
            } elseif ($part === 'colors') {
                $type = 'colors';
                $foundType = true;
            } elseif ($part === 'variables') {
                $type = 'variable';
                $foundType = true;
            } elseif ($part === 'frontend') {
                $area = 'frontend';
            } elseif ($part === 'backend') {
                $area = 'backend';
            } elseif ($foundType && !$category) {
                // 类型目录下的第一个非类型目录作为分类（如 homepage, product 等）
                // 对于 colors 和 variables 类型，category 应该为空（因为这些目录本身就是类型目录）
                if ($type !== 'colors' && $type !== 'variable') {
                $category = $part;
                }
            }
        }

        // 使用 meta key + 文件名作为标识（保持点分隔格式）
        // 这样同一目录下的不同文件会有不同的 identify，避免相互覆盖
        $identify = $metaKey . '.' . $fileName;

        // 计算文件相对于扫描路径的相对路径
        $filePathNormalized = str_replace('\\', '/', $filePath);
        $scanPathNormalized = rtrim(str_replace('\\', '/', $scanPath), '/');
        $relativePath = str_replace($scanPathNormalized . '/', '', $filePathNormalized);
        $relativePath = trim($relativePath, '/');
        
        // file_path 格式：模块名::{命名空间}/{相对路径}
        // 因为这些都是主题模板，文件路径应该从 base_path 的命名空间开始
        $filePathFormatted = $moduleName . '::' . $namespace . '/' . $relativePath;
        
        // file_full_path 格式：从项目根目录（BP）开始的完整路径
        // 确保 BP 路径和文件路径都使用相同的路径分隔符
        $bpNormalized = rtrim(str_replace('\\', '/', BP), '/');
        $fileFullPathFormatted = str_replace($bpNormalized . '/', '', $filePathNormalized);
        $fileFullPathFormatted = trim($fileFullPathFormatted, '/');
        
        // 构建元数据（只包含目录结构信息，所有路径部分都是 group）
        $metaDataArray = [
            'namespace' => $namespace,
            'meta_key' => $metaKey,
            'is_group' => true, // 标记为 group
            'file_path' => $filePathFormatted,
            'file_full_path' => $fileFullPathFormatted,
            'type' => $type,
            'area' => $area,
            'category' => $category,
            'file_name' => $fileName,
            'path_parts' => $pathParts,
            'groups' => $pathParts, // 所有路径部分都是 group
        ];
        
        // 合并其他标记（如 @preview.login 等）
        if (!empty($otherTags)) {
            $metaDataArray = array_merge($metaDataArray, $otherTags);
        }

        // 检查是否已存在（使用 namespace + type + identify 作为唯一键）
        // 使用新实例查询，避免状态污染
        /** @var Meta $queryModel */
        $queryModel = ObjectManager::make(Meta::class);
        $existing = $queryModel->reset()
                              ->where(Meta::fields_NAMESPACE, $namespace)
                              ->where(Meta::fields_META_TYPE, $type)
                              ->where(Meta::fields_META_IDENTIFY, $identify)
                              ->find()
                              ->fetch();
        
        // 准备 setting JSON（存储参数配置）
        $settingJson = !empty($setting) ? json_encode($setting, JSON_UNESCAPED_UNICODE) : null;
        
        $savedMeta = null;
        if ($existing && $existing->getId()) {
            // 更新 - 使用新实例加载记录，避免状态污染
            /** @var Meta $updateModel */
            $updateModel = ObjectManager::make(Meta::class);
            $updateModel->load($existing->getId());
            $updateModel->setData(Meta::fields_META_DATA, json_encode($metaDataArray, JSON_UNESCAPED_UNICODE));
            $updateModel->setData(Meta::fields_SETTING, $settingJson);
            $updateModel->setData(Meta::fields_FILE_PATH, $metaDataArray['file_path']);
            $updateModel->setData(Meta::fields_FILE_FULL_PATH, $metaDataArray['file_full_path']);
            if ($area) {
                $updateModel->setData(Meta::fields_AREA, $area);
            }
            if ($category) {
                $updateModel->setData(Meta::fields_CATEGORY, $category);
            }
            $updateModel->save();
            $savedMeta = $updateModel;
        } else {
            // 创建新记录，使用 forceCheck 确保唯一键检查
            /** @var Meta $newMeta */
            $newMeta = ObjectManager::make(Meta::class);
            $newMeta->reset();
            $newMeta->setData(Meta::fields_NAMESPACE, $namespace);
            $newMeta->setData(Meta::fields_META_TYPE, $type);
            $newMeta->setData(Meta::fields_META_IDENTIFY, $identify);
            $newMeta->setData(Meta::fields_FILE_PATH, $metaDataArray['file_path']);
            $newMeta->setData(Meta::fields_FILE_FULL_PATH, $metaDataArray['file_full_path']);
            $newMeta->setData(Meta::fields_META_DATA, json_encode($metaDataArray, JSON_UNESCAPED_UNICODE));
            $newMeta->setData(Meta::fields_SETTING, $settingJson);
            
            if ($area) {
                $newMeta->setData(Meta::fields_AREA, $area);
            }
            if ($category) {
                $newMeta->setData(Meta::fields_CATEGORY, $category);
            }
            
            // 使用 forceCheck 确保唯一键检查，如果已存在则更新
            $newMeta->forceCheck(true, [Meta::fields_NAMESPACE, Meta::fields_META_TYPE, Meta::fields_META_IDENTIFY])
                    ->save();
            $savedMeta = $newMeta;
        }
    }
    
    /**
     * 存储 Meta 字段到数据库
     * 
     * @param Meta $metaModel Meta 模型
     * @param string $namespace 命名空间
     * @param string $fullMetaKey 完整的 meta key（如 theme.frontend.layouts.default.name）
     * @param string $filePath 文件路径
     * @param array $pathParts 路径部分数组
     * @param string $fileName 文件名（不含扩展名）
     * @param string $moduleName 模块名
     * @param string $scanPath 扫描路径
     * @param string $fieldName 字段名（如 name, description）
     * @param array $attributes 字段属性
     * @param bool $verbose 是否详细输出
     */
    protected function storeMetaField(Meta $metaModel, string $namespace, string $fullMetaKey, string $filePath, array $pathParts, string $fileName, string $moduleName, string $scanPath, string $fieldName, array $attributes, bool $verbose = false): void
    {
        // 确定类型和标识（根据路径结构推断）
        $type = 'field'; // Meta 字段类型
        $category = null;
        $area = null;
        
        // 尝试从路径中提取区域和分类
        $foundType = false;
        foreach ($pathParts as $part) {
            if ($part === 'frontend') {
                $area = 'frontend';
            } elseif ($part === 'backend') {
                $area = 'backend';
            } elseif (in_array($part, ['layouts', 'components', 'partials'])) {
                // 标记找到了类型目录
                $foundType = true;
            } elseif ($foundType && !$category) {
                // 类型目录下的第一个非类型目录作为分类（如 homepage, product 等）
                $category = $part;
            }
        }
        
        // 使用 meta key 作为标识（保持点分隔格式）
        $identify = $fullMetaKey;
        
        // 计算文件相对于扫描路径的相对路径
        $filePathNormalized = str_replace('\\', '/', $filePath);
        $scanPathNormalized = rtrim(str_replace('\\', '/', $scanPath), '/');
        $relativePath = str_replace($scanPathNormalized . '/', '', $filePathNormalized);
        $relativePath = trim($relativePath, '/');
        
        // file_path 格式：模块名::{命名空间}/{相对路径}
        $filePathFormatted = $moduleName . '::' . $namespace . '/' . $relativePath;
        
        // file_full_path 格式：从项目根目录（BP）开始的完整路径
        $bpNormalized = rtrim(str_replace('\\', '/', BP), '/');
        $fileFullPathFormatted = str_replace($bpNormalized . '/', '', $filePathNormalized);
        $fileFullPathFormatted = trim($fileFullPathFormatted, '/');
        
        // 构建元数据
        $metaDataArray = [
            'namespace' => $namespace,
            'meta_key' => $fullMetaKey,
            'is_field' => true, // 标记为 field
            'field_name' => $fieldName,
            'file_path' => $filePathFormatted,
            'file_full_path' => $fileFullPathFormatted,
            'type' => $type,
            'area' => $area,
            'category' => $category,
            'file_name' => $fileName,
            'path_parts' => $pathParts,
            'attributes' => $attributes, // 字段属性
        ];
        
        // 检查是否已存在（使用 namespace + type + identify 作为唯一键）
        // 使用新实例查询，避免状态污染
        /** @var Meta $queryModel */
        $queryModel = ObjectManager::make(Meta::class);
        $existing = $queryModel->reset()
                              ->where(Meta::fields_NAMESPACE, $namespace)
                              ->where(Meta::fields_META_TYPE, $type)
                              ->where(Meta::fields_META_IDENTIFY, $identify)
                              ->find()
                              ->fetch();
        
        $savedMeta = null;
        if ($existing && $existing->getId()) {
            // 更新 - 使用新实例加载记录，避免状态污染
            /** @var Meta $updateModel */
            $updateModel = ObjectManager::make(Meta::class);
            $updateModel->load($existing->getId());
            $updateModel->setData(Meta::fields_META_DATA, json_encode($metaDataArray, JSON_UNESCAPED_UNICODE));
            $updateModel->setData(Meta::fields_FILE_PATH, $filePathFormatted);
            $updateModel->setData(Meta::fields_FILE_FULL_PATH, $fileFullPathFormatted);
            if ($area) {
                $updateModel->setData(Meta::fields_AREA, $area);
            }
            if ($category) {
                $updateModel->setData(Meta::fields_CATEGORY, $category);
            }
            $updateModel->save();
            $savedMeta = $updateModel;
        } else {
            // 新建 - 使用 ObjectManager::make 创建新实例，确保状态干净
            /** @var Meta $newMeta */
            $newMeta = ObjectManager::make(Meta::class);
            $newMeta->reset();
            $newMeta->setData(Meta::fields_NAMESPACE, $namespace);
            $newMeta->setData(Meta::fields_META_TYPE, $type);
            $newMeta->setData(Meta::fields_META_IDENTIFY, $identify);
            $newMeta->setData(Meta::fields_META_DATA, json_encode($metaDataArray, JSON_UNESCAPED_UNICODE));
            $newMeta->setData(Meta::fields_FILE_PATH, $filePathFormatted);
            $newMeta->setData(Meta::fields_FILE_FULL_PATH, $fileFullPathFormatted);
            if ($area) {
                $newMeta->setData(Meta::fields_AREA, $area);
            }
            if ($category) {
                $newMeta->setData(Meta::fields_CATEGORY, $category);
            }
            
            // 使用 forceCheck 确保唯一键检查，如果已存在则更新
            $newMeta->forceCheck(true, [Meta::fields_NAMESPACE, Meta::fields_META_TYPE, Meta::fields_META_IDENTIFY])
                    ->save();
            $savedMeta = $newMeta;
        }
        
        // 收集field类型的翻译字段并发送到i18n事件
        if ($savedMeta && $savedMeta->getId()) {
            $this->collectFieldTranslations($savedMeta, $attributes);
        }
    }
    
    /**
     * 收集field类型的翻译字段并发送到i18n事件
     * 
     * @param Meta $meta Meta记录对象
     * @param array $attributes 字段属性数组
     */
    protected function collectFieldTranslations($meta, array $attributes): void
    {
        if (empty($attributes) || !is_array($attributes)) {
            return;
        }
        
        $translations = [];
        $namespace = $meta->getData(Meta::fields_NAMESPACE);
        $identify = $meta->getData(Meta::fields_META_IDENTIFY);
        
        // 可翻译的字段列表（通常包括name、description等）
        $translatableFields = ['name', 'description', 'label', 'title', 'placeholder', 'help', 'hint'];
        
        foreach ($translatableFields as $field) {
            if (isset($attributes[$field])) {
                $value = $attributes[$field];
                
                // 如果值是数组，尝试获取name或default值
                if (is_array($value)) {
                    $translationValue = $value['name'] ?? $value['default'] ?? null;
                } else {
                    $translationValue = $value;
                }
                
                // 如果值不为空，添加到翻译列表
                if (!empty($translationValue) && is_string($translationValue)) {
                    // 构建翻译键：@meta::{namespace}.field.{identify}.{field}
                    $translationKey = "@meta::{$namespace}.field.{$identify}.{$field}";
                    
                    $translations[] = [
                        'word' => $translationKey,
                        'translate' => $translationValue,
                        'module' => 'Weline_Meta'
                    ];
                }
            }
        }
        
        // 触发翻译收集事件
        if (!empty($translations)) {
            try {
                /** @var \Weline\Framework\Event\EventsManager $eventsManager */
                $eventsManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
                // 将数组赋值给变量，以便作为引用参数传递
                $eventData = [
                    'translations' => $translations,
                    'module' => 'Weline_Meta'
                ];
                $eventsManager->dispatch('Weline_I18n::collect_translations', $eventData);
            } catch (\Exception $e) {
                // 如果事件系统不可用，忽略错误（避免影响Meta扫描）
                if ($this->verbose ?? false) {
                    echo "警告：无法发送翻译收集事件：" . $e->getMessage() . "\n";
                }
            }
        }
    }

    public function tip(): string
    {
        return __('扫描 @meta.json 规约文件并存储元数据到数据库');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'meta:scan:convention',
            __('扫描 @meta.json 规约文件并存储元数据到数据库'),
            [
                '-m, --module' => __('模块名称（可选，不指定则扫描所有模块）'),
                '-v, --verbose' => __('显示详细信息'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'module' => __('模块名称，例如：Weline_Theme（可选）'),
            ],
            [
                __('扫描所有模块') => 'php bin/w meta:collect',
                __('扫描指定模块') => 'php bin/w meta:collect --module Weline_Theme',
                __('使用完整命令名') => 'php bin/w meta:scan:convention --module Weline_Theme',
                __('详细输出') => 'php bin/w meta:collect --module Weline_Theme --verbose',
                __('简短参数') => 'php bin/w meta:collect -m Weline_Theme -v',
            ]
        );
    }

    public function aliases(): array
    {
        return ['meta:collect', 'meta:convention'];
    }
}

