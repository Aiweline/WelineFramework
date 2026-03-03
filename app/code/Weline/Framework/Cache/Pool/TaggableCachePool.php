<?php

declare(strict_types=1);

/**
 * 可标签缓存池
 * 
 * 在 CachePool 基础上增加标签支持。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Pool;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\TaggableInterface;

class TaggableCachePool extends CachePool implements TaggableInterface
{
    /**
     * 标签索引：tag => [key1, key2, ...]
     */
    private array $tagIndex = [];

    /**
     * 键的标签映射：key => [tag1, tag2, ...]
     */
    private array $keyTags = [];

    public function __construct(
        string $identity,
        CacheAdapterInterface $adapter,
        string $tip = '',
        bool $permanent = false,
        int $defaultTtl = 1800
    ) {
        parent::__construct($identity, $adapter, $tip, $permanent, $defaultTtl);
    }

    public function setWithTags(string $key, mixed $value, array $tags, int $ttl = 0): bool
    {
        $builtKey = $this->buildKey($key);

        $this->removeKeyFromTags($key);

        foreach ($tags as $tag) {
            if (!isset($this->tagIndex[$tag])) {
                $this->tagIndex[$tag] = [];
            }
            $this->tagIndex[$tag][] = $key;
        }
        
        $this->keyTags[$key] = $tags;

        return $this->set($key, $value, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        $keysToDelete = [];
        
        foreach ($tags as $tag) {
            if (isset($this->tagIndex[$tag])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tag]);
                unset($this->tagIndex[$tag]);
            }
        }

        $keysToDelete = array_unique($keysToDelete);

        foreach ($keysToDelete as $key) {
            $this->delete($key);
            unset($this->keyTags[$key]);
        }
        
        return true;
    }

    public function getKeysByTag(string $tag): array
    {
        return $this->tagIndex[$tag] ?? [];
    }

    public function delete(string $key): bool
    {
        $this->removeKeyFromTags($key);
        return parent::delete($key);
    }

    public function clear(): bool
    {
        $this->tagIndex = [];
        $this->keyTags = [];
        return parent::clear();
    }

    /**
     * 从标签索引中移除键
     */
    private function removeKeyFromTags(string $key): void
    {
        if (isset($this->keyTags[$key])) {
            foreach ($this->keyTags[$key] as $tag) {
                if (isset($this->tagIndex[$tag])) {
                    $pos = array_search($key, $this->tagIndex[$tag], true);
                    if ($pos !== false) {
                        unset($this->tagIndex[$tag][$pos]);
                        $this->tagIndex[$tag] = array_values($this->tagIndex[$tag]);
                    }
                    if (empty($this->tagIndex[$tag])) {
                        unset($this->tagIndex[$tag]);
                    }
                }
            }
            unset($this->keyTags[$key]);
        }
    }

    /**
     * 获取所有标签
     */
    public function getAllTags(): array
    {
        return array_keys($this->tagIndex);
    }

    /**
     * 获取标签统计
     */
    public function getTagStats(): array
    {
        $stats = [];
        
        foreach ($this->tagIndex as $tag => $keys) {
            $stats[$tag] = count($keys);
        }
        
        return $stats;
    }

    public function getStats(): array
    {
        return array_merge(parent::getStats(), [
            'tags_count' => count($this->tagIndex),
            'tagged_keys_count' => count($this->keyTags),
            'taggable' => true,
        ]);
    }
}
