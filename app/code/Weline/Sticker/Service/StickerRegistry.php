<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Framework\App\Env;

/**
 * Sticker 注册表管理服务
 * 管理 generated/sticker.php 缓存文件的读取和写入
 */
class StickerRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'sticker.php';

    private ?array $cachedRegistry = null;
    private ?int $cachedFileMtime = null;

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
        $this->cachedFileMtime = filemtime(self::REGISTRY_FILE);

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
        $content .= "// Sticker 注册表\n";
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
            $this->cachedFileMtime = filemtime(self::REGISTRY_FILE);
            return true;
        }

        return false;
    }

    /**
     * 检查文件是否有 Sticker
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @return bool
     */
    public function hasSticker(string $targetModule, string $targetFile): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$targetModule][$targetFile]) && 
               !empty($registry[$targetModule][$targetFile]);
    }

    /**
     * 获取文件的 Sticker 规则
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件路径
     * @return array
     */
    public function getFileStickers(string $targetModule, string $targetFile): array
    {
        $registry = $this->getRegistry();
        return $registry[$targetModule][$targetFile] ?? [];
    }

    /**
     * 检查模块是否有 Sticker（快速判断）
     *
     * @param string $targetModule 目标模块
     * @return bool
     */
    public function hasModuleStickers(string $targetModule): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[$targetModule]) && !empty($registry[$targetModule]);
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->cachedRegistry = null;
        $this->cachedFileMtime = null;
    }

    /**
     * 从扫描结果构建注册表
     *
     * @param array $scannedStickers 扫描结果
     * @param RuleParser $ruleParser 规则解析器
     * @return array
     */
    public function buildRegistryFromScanned(array $scannedStickers, RuleParser $ruleParser): array
    {
        $registry = [];

        foreach ($scannedStickers as $sticker) {
            $targetModule = $sticker['target_module'];
            $targetFile = $sticker['target_file'];
            $sourceModule = $sticker['source_module'];
            $stickerFile = $sticker['sticker_file'];

            // 解析 Sticker 文件
            $rules = $ruleParser->parseStickerFile($stickerFile);

            if (empty($rules)) {
                continue;
            }

            // 初始化结构
            if (!isset($registry[$targetModule])) {
                $registry[$targetModule] = [];
            }
            if (!isset($registry[$targetModule][$targetFile])) {
                $registry[$targetModule][$targetFile] = [];
            }

            // 添加规则
            $registry[$targetModule][$targetFile][] = [
                'source_module' => $sourceModule,
                'sticker_file' => $stickerFile,
                'sticker_relative_path' => $sticker['sticker_relative_path'] ?? '',
                'actions' => $rules
            ];
        }

        return $registry;
    }
}

