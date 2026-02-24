<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Service;

/**
 * 部件注册表管理
 * 管理 generated/widgets.php 文件的读取和写入
 */
class WidgetRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'widgets.php';

    // 使用静态缓存，跨实例共享，提升性能
    private static ?array $staticCachedRegistry = null;
    private static ?int $staticCachedFileMtime = null;
    
    private WidgetScanner $scanner;

    public function __construct(WidgetScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * 获取注册表内容
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 使用静态缓存，跨实例共享，避免每次请求都读取文件
        if (!$forceReload && self::$staticCachedRegistry !== null) {
            if (!file_exists(self::REGISTRY_FILE)) {
                return self::$staticCachedRegistry;
            }
            $currentMtime = filemtime(self::REGISTRY_FILE);
            $mtimeUnchanged = self::$staticCachedFileMtime !== null && $currentMtime === self::$staticCachedFileMtime;
            // 缓存非空且 mtime 未变：直接返回
            if (self::$staticCachedRegistry !== [] && $mtimeUnchanged) {
                return self::$staticCachedRegistry;
            }
            // 缓存为空：若文件可能已有内容（mtime 变了或文件较大）则重载，否则返回空
            if (self::$staticCachedRegistry === []) {
                if (!$mtimeUnchanged || filesize(self::REGISTRY_FILE) > 100) {
                    // 重载
                } else {
                    return self::$staticCachedRegistry;
                }
            } elseif ($mtimeUnchanged) {
                return self::$staticCachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            self::$staticCachedRegistry = [];
            self::$staticCachedFileMtime = 0;
            return [];
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = [];
        }

        // 更新静态缓存
        self::$staticCachedRegistry = $registry;
        self::$staticCachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     *
     * @return bool
     */
    public function refresh(): bool
    {
        // 扫描所有部件
        $scannedData = $this->scanner->scanAllWidgets();

        // 保存注册表
        return $this->saveRegistry($scannedData);
    }

    /**
     * 保存注册表到文件
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    private function saveRegistry(array $registry): bool
    {
        try {
            $registryDir = dirname(self::REGISTRY_FILE);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }

            $content = "<?php\n";
            $content .= "/**\n";
            $content .= " * 部件注册表\n";
            $content .= " * 此文件由系统自动生成，请勿手动修改\n";
            $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
            $content .= " */\n\n";
            $content .= "return " . var_export($registry, true) . ";\n";

            $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);
            
            if ($result !== false) {
                // 清除静态缓存
                self::$staticCachedRegistry = null;
                self::$staticCachedFileMtime = null;
                
                // 清除 WidgetData 的缓存
                \Weline\Widget\Service\WidgetData::clearCache();
                
                return true;
            }

            return false;
        } catch (\Exception $e) {
            error_log("保存部件注册表失败: " . $e->getMessage());
            return false;
        }
    }
}
