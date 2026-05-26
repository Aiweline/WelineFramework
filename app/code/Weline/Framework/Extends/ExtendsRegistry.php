<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends;

use Weline\Framework\App\Env;
use Weline\Framework\Registry\Service\RegistryProgress;

/**
 * 扩展注册表管理
 * 管理 generated/extends.php 文件的读取和写入
 */
class ExtendsRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'extends.php';

    private ?array $cachedRegistry = null;
    private ?int $cachedFileMtime = null;
    private ExtendsScanner $scanner;
    private CompletenessChecker $completenessChecker;

    public function __construct(
        ExtendsScanner $scanner,
        CompletenessChecker $completenessChecker
    ) {
        $this->scanner = $scanner;
        $this->completenessChecker = $completenessChecker;
    }

    /**
     * 获取注册表内容
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedRegistry !== null) {
            $currentMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            if ($currentMtime === $this->cachedFileMtime) {
                return $this->cachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            $this->cachedRegistry = [];
            $this->cachedFileMtime = 0;
            return [];
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = [];
        }

        $this->cachedRegistry = $registry;
        $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     *
     * @return bool
     */
    public function refresh(): bool
    {
        // 扫描所有扩展
        RegistryProgress::log('Extends scan: all modules started');
        $scannedData = $this->scanner->scanAllExtends();
        RegistryProgress::count('Extends scan', count($scannedData), 'modules with extends data');

        // 进行完备性检查
        RegistryProgress::log('Extends completeness check started');
        $completenessReport = $this->completenessChecker->checkAll($scannedData);
        RegistryProgress::count('Extends completeness check', count($completenessReport), 'module reports');

        // 组织数据结构
        RegistryProgress::log('Extends organize registry data');
        $registry = $this->organizeRegistryData($scannedData, $completenessReport);
        RegistryProgress::count('Extends registry', count($registry), 'modules organized');
        unset($scannedData, $completenessReport);
        RegistryProgress::log('Extends raw scan data released');

        // 保存注册表
        return $this->saveRegistry($registry);
    }
    
    /**
     * 增量刷新指定模块的扩展注册表
     * 仅重新扫描指定模块的扩展，合并到现有注册表
     *
     * @param array $moduleNames 需要刷新的模块名列表
     * @return bool
     */
    public function refreshForModules(array $moduleNames): bool
    {
        // 1. 加载现有注册表
        RegistryProgress::log('Extends incremental: loading current registry');
        $registry = $this->getRegistry(true);

        // 清理已卸载/禁用模块残留，避免对无效模块继续做 extends 完备性/继承关系处理
        $this->purgeInactiveModulesFromRegistry($registry);
        
        // 2. 清除目标模块的旧数据
        RegistryProgress::log('Extends incremental: clearing modules ' . implode(', ', $moduleNames));
        $this->clearModuleExtends($registry, $moduleNames);
        
        // 3. 扫描目标模块的新数据
        RegistryProgress::log('Extends incremental: scanning target modules');
        $scannedData = $this->scanner->scanModules($moduleNames);
        RegistryProgress::count('Extends incremental scan', count($scannedData), 'modules with extends data');
        
        // 4. 进行完备性检查（仅对扫描的数据）
        RegistryProgress::log('Extends incremental: completeness check');
        $completenessReport = $this->completenessChecker->checkAll($scannedData);
        
        // 5. 组织新数据
        RegistryProgress::log('Extends incremental: organizing new data');
        $newRegistry = $this->organizeRegistryData($scannedData, $completenessReport);
        unset($scannedData, $completenessReport);
        RegistryProgress::log('Extends incremental raw scan data released');
        
        // 6. 合并到现有注册表
        RegistryProgress::log('Extends incremental: merging into current registry');
        $this->mergeExtendsRegistry($registry, $newRegistry);
        unset($newRegistry);
        
        // 7. 保存注册表
        return $this->saveRegistry($registry);
    }

    /**
     * 清理扩展注册表中已卸载或禁用模块的残留数据。
     */
    private function purgeInactiveModulesFromRegistry(array &$registry): void
    {
        $env = Env::getInstance();

        foreach (array_keys($registry) as $moduleName) {
            if (!$env->getModuleStatus((string)$moduleName)) {
                unset($registry[$moduleName]);
            }
        }

        foreach ($registry as $targetModule => &$targetData) {
            if (!isset($targetData['extended_by']) || !is_array($targetData['extended_by'])) {
                continue;
            }

            foreach (array_keys($targetData['extended_by']) as $sourceModule) {
                if (!$env->getModuleStatus((string)$sourceModule)) {
                    unset($targetData['extended_by'][$sourceModule]);
                }
            }
        }
        unset($targetData);
    }
    
    /**
     * 清除指定模块的扩展数据
     *
     * @param array &$registry 注册表数据（引用传递）
     * @param array $moduleNames 要清除的模块名列表
     * @return void
     */
    private function clearModuleExtends(array &$registry, array $moduleNames): void
    {
        foreach ($moduleNames as $moduleName) {
            // 1. 清除模块自身的定义
            if (isset($registry[$moduleName])) {
                unset($registry[$moduleName]);
            }
            
            // 2. 清除其他模块中由该模块提供的扩展
            foreach ($registry as $targetModule => &$targetData) {
                if (isset($targetData['extended_by'][$moduleName])) {
                    unset($targetData['extended_by'][$moduleName]);
                }
            }
        }
    }
    
    /**
     * 合并扩展注册表
     *
     * @param array &$registry 现有注册表（引用传递）
     * @param array $newRegistry 新注册表数据
     * @return void
     */
    private function mergeExtendsRegistry(array &$registry, array $newRegistry): void
    {
        foreach ($newRegistry as $moduleName => $moduleData) {
            if (!isset($registry[$moduleName])) {
                $registry[$moduleName] = [
                    'extends' => [],
                    'extended_by' => []
                ];
            }
            
            // 合并 extends（如果新数据有定义）
            if (!empty($moduleData['extends'])) {
                $registry[$moduleName]['extends'] = $moduleData['extends'];
            }
            
            // 合并 extended_by
            if (!empty($moduleData['extended_by'])) {
                foreach ($moduleData['extended_by'] as $sourceModule => $extensions) {
                    if (!isset($registry[$moduleName]['extended_by'][$sourceModule])) {
                        $registry[$moduleName]['extended_by'][$sourceModule] = [];
                    }
                    $registry[$moduleName]['extended_by'][$sourceModule] = array_merge(
                        $registry[$moduleName]['extended_by'][$sourceModule],
                        $extensions
                    );
                }
            }
            
            // 合并 completeness
            if (isset($moduleData['completeness'])) {
                $registry[$moduleName]['completeness'] = $moduleData['completeness'];
            }
        }
    }

    /**
     * 组织注册表数据
     * 简化数据结构，只保留模块级别的核心信息
     *
     * @param array $scannedData 扫描的数据
     * @param array $completenessReport 完备性检查报告
     * @return array
     */
    private function organizeRegistryData(array $scannedData, array $completenessReport): array
    {
        $registry = [];
        $env = Env::getInstance();

        foreach ($scannedData as $moduleName => $data) {
            // 验证模块名格式，只保留有效的模块名
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $moduleName)) {
                continue; // 跳过无效的模块名（如 DEV-workspace）
            }
            
            $registry[$moduleName] = [
                'extends' => $data['extends'] ?? [],
                'extended_by' => []
            ];

            // 组织扩展信息，只保留核心字段
            if (!empty($data['extended_by'])) {
                foreach ($data['extended_by'] as $sourceModule => $extendList) {
                    // 验证源模块名格式
                    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $sourceModule)) {
                        continue; // 跳过无效的源模块名
                    }
                    
                    // 检查源模块（提供扩展的模块）的激活状态
                    // 如果源模块被禁用，跳过该扩展（依赖Extends衍生功能需要源模块激活）
                    if (!$env->getModuleStatus($sourceModule)) {
                        continue; // 跳过源模块被禁用的扩展
                    }
                    
                    // 初始化类型分组
                    if (!isset($registry[$moduleName]['extended_by'][$sourceModule])) {
                        $registry[$moduleName]['extended_by'][$sourceModule] = [];
                    }
                    
                    foreach ($extendList as $extendInfo) {
                        // 只保留核心字段，移除冗余信息
                        $coreInfo = [
                            'type' => $extendInfo['type'] ?? 'module',
                            'source_module' => $sourceModule,
                            'source_module_status' => true, // 已通过状态检查
                            'source_file' => $extendInfo['source_file'] ?? '',
                            'file_path' => $extendInfo['file_path'] ?? '',
                            'relative_path' => $extendInfo['relative_path'] ?? ''
                        ];
                        
                        // 如果是 Sticker 扩展，添加特殊标记
                        if (($extendInfo['is_sticker_extension'] ?? false) === true) {
                            $coreInfo['is_sticker_extension'] = true;
                            $coreInfo['sticker_type'] = $extendInfo['sticker_type'] ?? $extendInfo['type'] ?? 'module';
                            if (isset($extendInfo['theme_name'])) {
                                $coreInfo['theme_name'] = $extendInfo['theme_name'];
                            }
                        }
                        
                        $registry[$moduleName]['extended_by'][$sourceModule][] = $coreInfo;
                    }
                }
            }

            // 添加完备性检查信息（简化版，只保留关键信息）
            if (isset($completenessReport[$moduleName])) {
                $completeness = $completenessReport[$moduleName];
                $registry[$moduleName]['completeness'] = [
                    'has_extends_php' => $completeness['has_extends_php'] ?? false,
                    'has_extends_md' => $completeness['has_extends_md'] ?? false,
                    'has_errors' => !empty($completeness['errors'] ?? []),
                    'error_count' => count($completeness['errors'] ?? []),
                    'warning_count' => count($completeness['warnings'] ?? [])
                ];
            }
        }

        return $registry;
    }

    /**
     * 增强 Sticker 扩展的元数据（已废弃，不再使用）
     * 
     * @deprecated 数据结构已简化，不再需要增强元数据
     * @param array &$moduleData 模块数据
     * @return void
     */
    private function enhanceStickerMetadata(array &$moduleData): void
    {
        // 不再增强元数据，保持数据结构简洁
    }

    /**
     * 增强单个扩展的元数据
     *
     * @param array $extension 扩展信息
     * @return array 增强后的扩展信息
     */
    private function enhanceExtensionMetadata(array $extension): array
    {
        $enhanced = $extension;
        
        // 添加文件类型信息
        $filePath = $extension['file_path'] ?? '';
        $enhanced['file_type'] = $this->getFileType($filePath);
        
        // 添加扩展复杂度评级
        $enhanced['complexity'] = $this->calculateExtensionComplexity($extension);
        
        // 添加最后修改时间（如果可获取）
        $sourceFile = $extension['source_file'] ?? '';
        if (!empty($sourceFile) && file_exists($sourceFile)) {
            $enhanced['last_modified'] = date('Y-m-d H:i:s', filemtime($sourceFile));
        }
        
        // 添加扩展影响范围评估
        $enhanced['impact_scope'] = $this->assessExtensionImpact($extension);
        
        return $enhanced;
    }

    /**
     * 获取文件类型
     *
     * @param string $filePath 文件路径
     * @return string
     */
    private function getFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $typeMap = [
            'php' => 'PHP',
            'phtml' => 'Template',
            'html' => 'HTML',
            'js' => 'JavaScript',
            'css' => 'CSS',
            'scss' => 'SCSS',
            'less' => 'LESS',
            'xml' => 'XML',
            'json' => 'JSON',
            'yaml' => 'YAML',
            'yml' => 'YAML',
            'md' => 'Markdown'
        ];
        
        return $typeMap[$extension] ?? 'Unknown';
    }

    /**
     * 计算扩展复杂度
     *
     * @param array $extension 扩展信息
     * @return string 复杂度等级：simple, medium, complex
     */
    private function calculateExtensionComplexity(array $extension): string
    {
        $filePath = $extension['file_path'] ?? '';
        $fileType = $this->getFileType($filePath);
        
        // 模板文件通常复杂度较高
        if ($fileType === 'Template') {
            return 'complex';
        }
        
        // 配置文件通常复杂度中等
        if (in_array($fileType, ['XML', 'JSON', 'YAML'])) {
            return 'medium';
        }
        
        // 静态文件通常复杂度较低
        if (in_array($fileType, ['CSS', 'JavaScript'])) {
            return 'medium';
        }
        
        return 'simple';
    }

    /**
     * 评估扩展影响范围
     *
     * @param array $extension 扩展信息
     * @return string 影响范围：local, global, critical
     */
    private function assessExtensionImpact(array $extension): string
    {
        $filePath = $extension['file_path'] ?? '';
        $pathParts = explode('/', $filePath);
        
        // 核心文件路径通常影响范围更大
        $criticalPaths = ['config', 'etc', 'di.xml', 'module.xml'];
        foreach ($criticalPaths as $criticalPath) {
            if (in_array($criticalPath, $pathParts)) {
                return 'critical';
            }
        }
        
        // 模板文件通常影响范围中等
        if (strpos($filePath, 'templates') !== false || strpos($filePath, 'view') !== false) {
            return 'global';
        }
        
        return 'local';
    }

    /**
     * 计算模块统计信息
     *
     * @param array $moduleData 模块数据
     * @return array 统计信息
     */
    private function calculateModuleStats(array $moduleData): array
    {
        $stats = [
            'total_extensions' => 0,
            'sticker_count' => 0,
            'module_count' => 0,
            'theme_count' => 0,
            'complexity_distribution' => [
                'simple' => 0,
                'medium' => 0,
                'complex' => 0
            ],
            'impact_distribution' => [
                'local' => 0,
                'global' => 0,
                'critical' => 0
            ],
            'file_type_distribution' => []
        ];
        
        $extendedBy = $moduleData['extended_by'] ?? [];
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                $stats['total_extensions']++;
                
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    $stats['sticker_count']++;
                } elseif (($extension['type'] ?? '') === 'module') {
                    $stats['module_count']++;
                } elseif (($extension['type'] ?? '') === 'theme') {
                    $stats['theme_count']++;
                }
                
                // 复杂度分布
                $complexity = $extension['complexity'] ?? 'simple';
                if (isset($stats['complexity_distribution'][$complexity])) {
                    $stats['complexity_distribution'][$complexity]++;
                }
                
                // 影响范围分布
                $impact = $extension['impact_scope'] ?? 'local';
                if (isset($stats['impact_distribution'][$impact])) {
                    $stats['impact_distribution'][$impact]++;
                }
                
                // 文件类型分布
                $fileType = $extension['file_type'] ?? 'Unknown';
                if (!isset($stats['file_type_distribution'][$fileType])) {
                    $stats['file_type_distribution'][$fileType] = 0;
                }
                $stats['file_type_distribution'][$fileType]++;
            }
        }
        
        return $stats;
    }

    /**
     * 保存注册表
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        RegistryProgress::log('Extends save registry: generated/extends.php');
        $content = "<?php return " . w_var_export($registry, true) . ";\n";

        // 确保目录存在
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

        if ($result !== false) {
            // 更新实例缓存
            $this->cachedRegistry = $registry;
            $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            
            // 清除 ExtendsData 的静态缓存，确保其他使用 ExtendsData 的代码能立即看到新生成的文件
            ExtendsData::clearCache();
            RegistryProgress::log('Extends save registry finished');
            
            return true;
        }

        RegistryProgress::log('Extends save registry failed');
        return false;
    }

    /**
     * 检查模块是否有扩展定义
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function clearMemoryCache(): void
    {
        $this->cachedRegistry = null;
        $this->cachedFileMtime = null;
    }

    public function hasExtends(string $moduleName): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$moduleName]) && !empty($registry[$moduleName]['extends']);
    }

    /**
     * 检查模块是否被其他模块扩展
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function isExtendedBy(string $moduleName): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$moduleName]) && !empty($registry[$moduleName]['extended_by']);
    }

    /**
     * 获取模块的扩展信息
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getModuleExtends(string $moduleName): array
    {
        $registry = $this->getRegistry();
        return $registry[$moduleName]['extends'] ?? [];
    }

    /**
     * 获取扩展该模块的其他模块信息
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getExtendedBy(string $moduleName): array
    {
        $registry = $this->getRegistry();
        return $registry[$moduleName]['extended_by'] ?? [];
    }

    /**
     * 获取扩展某模块的所有模块（快速查询）
     *
     * @param string $moduleName 目标模块名
     * @return array 返回格式：['Weline_MyModule' => [...扩展信息...]]
     */
    public function getModuleExtendedBy(string $moduleName): array
    {
        $registry = $this->getRegistry();
        $extendedBy = $registry[$moduleName]['extended_by'] ?? [];
        
        // 按扩展类型分组
        $result = [
            'module_extensions' => [],
            'theme_extensions' => [],
            'sticker_extensions' => []
        ];
        
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                $type = $extension['type'] ?? 'unknown';
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    $result['sticker_extensions'][$sourceModule][] = $extension;
                } elseif ($type === 'module') {
                    $result['module_extensions'][$sourceModule][] = $extension;
                } elseif ($type === 'theme') {
                    $result['theme_extensions'][$sourceModule][] = $extension;
                } else {
                    // 未知类型，放到 module_extensions 中
                    $result['module_extensions'][$sourceModule][] = $extension;
                }
            }
        }
        
        return $result;
    }

    /**
     * 获取扩展类型
     *
     * @param string $moduleName 模块名
     * @param string $extendName 扩展点名
     * @return string|null
     */
    public function getExtendType(string $moduleName, string $extendName): ?string
    {
        $registry = $this->getRegistry();
        $extends = $registry[$moduleName]['extends'] ?? [];
        
        if (isset($extends['extends'][$extendName]['type'])) {
            $type = $extends['extends'][$extendName]['type'];
            if (is_array($type)) {
                return implode(',', $type);
            }
            return $type;
        }
        
        return null;
    }

    /**
     * 检查是否有特定类型的扩展
     *
     * @param string $moduleName 模块名
     * @param string $extendType 扩展类型 (module/theme/sticker)
     * @return bool
     */
    public function hasExtendType(string $moduleName, string $extendType): bool
    {
        $registry = $this->getRegistry();
        
        // 检查定义的扩展点
        $extends = $registry[$moduleName]['extends'] ?? [];
        if (isset($extends['extends'])) {
            foreach ($extends['extends'] as $extendConfig) {
                $type = $extendConfig['type'] ?? '';
                if (is_array($type)) {
                    if (in_array($extendType, $type)) {
                        return true;
                    }
                } elseif ($type === $extendType) {
                    return true;
                }
            }
        }
        
        // 检查被扩展的情况
        $extendedBy = $registry[$moduleName]['extended_by'] ?? [];
        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) && $extendType === 'sticker') {
                    return true;
                }
                if (($extension['type'] ?? '') === $extendType) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 获取所有 Sticker 扩展信息
     *
     * @return array
     */
    public function getAllStickerExtensions(): array
    {
        $registry = $this->getRegistry();
        $stickerExtensions = [];
        
        foreach ($registry as $moduleName => $data) {
            $extendedBy = $data['extended_by'] ?? [];
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        if (!isset($stickerExtensions[$sourceModule])) {
                            $stickerExtensions[$sourceModule] = [];
                        }
                        $stickerExtensions[$sourceModule][] = array_merge($extension, [
                            'target_module' => $moduleName
                        ]);
                    }
                }
            }
        }
        
        return $stickerExtensions;
    }

    /**
     * 获取模块的 Sticker 扩展信息
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getModuleStickerExtensions(string $moduleName): array
    {
        $extendedBy = $this->getModuleExtendedBy($moduleName);
        return $extendedBy['sticker_extensions'] ?? [];
    }

    /**
     * 检查模块是否被 Sticker 扩展
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function isStickerExtended(string $moduleName): bool
    {
        $stickerExtensions = $this->getModuleStickerExtensions($moduleName);
        return !empty($stickerExtensions);
    }

    /**
     * 获取扩展统计信息
     *
     * @return array
     */
    public function getExtensionStats(): array
    {
        $registry = $this->getRegistry();
        $stats = [
            'total_modules' => count($registry),
            'modules_with_extends' => 0,
            'modules_extended_by_others' => 0,
            'sticker_extensions_count' => 0,
            'module_extensions_count' => 0,
            'theme_extensions_count' => 0,
            'extension_types' => []
        ];
        
        foreach ($registry as $moduleName => $data) {
            // 统计有扩展定义的模块
            if (!empty($data['extends'])) {
                $stats['modules_with_extends']++;
            }
            
            // 统计被扩展的模块
            if (!empty($data['extended_by'])) {
                $stats['modules_extended_by_others']++;
            }
            
            // 统计扩展类型
            $extendedBy = $data['extended_by'] ?? [];
            foreach ($extendedBy as $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        $stats['sticker_extensions_count']++;
                    } elseif (($extension['type'] ?? '') === 'module') {
                        $stats['module_extensions_count']++;
                    } elseif (($extension['type'] ?? '') === 'theme') {
                        $stats['theme_extensions_count']++;
                    }
                }
            }
        }
        
        return $stats;
    }
}

