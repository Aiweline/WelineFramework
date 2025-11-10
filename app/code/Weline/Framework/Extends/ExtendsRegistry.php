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
        $scannedData = $this->scanner->scanAllExtends();

        // 进行完备性检查
        $completenessReport = $this->completenessChecker->checkAll($scannedData);

        // 组织数据结构
        $registry = $this->organizeRegistryData($scannedData, $completenessReport);

        // 保存注册表
        return $this->saveRegistry($registry);
    }

    /**
     * 组织注册表数据
     *
     * @param array $scannedData 扫描的数据
     * @param array $completenessReport 完备性检查报告
     * @return array
     */
    private function organizeRegistryData(array $scannedData, array $completenessReport): array
    {
        $registry = [];

        foreach ($scannedData as $moduleName => $data) {
            $registry[$moduleName] = [
                'extends' => $data['extends'] ?? [],
                'extended_by' => []
            ];

            // 组织扩展信息
            if (!empty($data['extended_by'])) {
                foreach ($data['extended_by'] as $sourceModule => $extendList) {
                    $registry[$moduleName]['extended_by'][$sourceModule] = $extendList;
                }
            }

            // 添加完备性检查信息
            if (isset($completenessReport[$moduleName])) {
                $registry[$moduleName]['completeness'] = $completenessReport[$moduleName];
            }
        }

        return $registry;
    }

    /**
     * 保存注册表
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        $content = "<?php\n";
        $content .= "// Extends 注册表\n";
        $content .= "// 自动生成，请勿手动修改\n";
        $content .= "// 生成时间: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "return " . var_export($registry, true) . ";\n";

        // 确保目录存在
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

        if ($result !== false) {
            $this->cachedRegistry = $registry;
            $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            return true;
        }

        return false;
    }

    /**
     * 检查模块是否有扩展定义
     *
     * @param string $moduleName 模块名
     * @return bool
     */
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
}

