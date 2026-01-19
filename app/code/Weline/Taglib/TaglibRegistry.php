<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Taglib;

/**
 * 标签库注册表管理
 * 管理 generated/taglibs.php 文件的读取
 */
class TaglibRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'taglibs.php';

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
            $this->cachedRegistry = ['tags' => []];
            $this->cachedFileMtime = 0;
            return $this->cachedRegistry;
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = ['tags' => []];
        }

        // 兼容旧格式（如果直接是 tags 数组）
        if (!isset($registry['tags']) && isset($registry[0])) {
            $registry = ['tags' => $registry];
        } elseif (!isset($registry['tags'])) {
            $registry = ['tags' => []];
        }

        $this->cachedRegistry = $registry;
        $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 获取标签列表
     *
     * @return array
     */
    public function getTags(): array
    {
        $registry = $this->getRegistry();
        return $registry['tags'] ?? [];
    }
}
