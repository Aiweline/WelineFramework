<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\FeedProviderInterface;

/**
 * Feed 注册服务
 * 
 * 负责扫描和注册所有 Feed Provider
 * 
 * @package Weline_Seo
 */
class FeedRegistryService
{
    private ObjectManager $objectManager;
    private ?array $cachedProviders = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有 Feed Provider 实例
     * 
     * @param bool $forceReload 强制重新加载
     * @return FeedProviderInterface[] Provider 实例数组
     */
    public function getProviders(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedProviders !== null) {
            $currentMtime = ExtendsData::getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedProviders;
            }
        }

        $providers = [];
        
        try {
            // 从 ExtendsData 读取所有扩展 Weline_Seo 的模块信息
            $extendedBy = ExtendsData::getExtendedBy('Weline_Seo', $forceReload);
            
            if (empty($extendedBy)) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
                return [];
            }

            // 获取 FeedProvider 扩展点信息
            $extends = ExtendsData::getModuleExtends('Weline_Seo', $forceReload);
            $feedProviderExtend = $extends['FeedProvider'] ?? null;
            
            if (!$feedProviderExtend) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
                return [];
            }

            // 遍历所有扩展模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 检查是否是 FeedProvider 扩展
                    if (($extension['extend_name'] ?? '') === 'FeedProvider') {
                        $class = $extension['class'] ?? null;
                        if ($class && class_exists($class)) {
                            try {
                                $instance = $this->objectManager->getInstance($class);
                                if ($instance instanceof FeedProviderInterface) {
                                    $providers[$instance->getCode()] = $instance;
                                }
                            } catch (\Exception $e) {
                                // 忽略错误，继续处理下一个
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 错误处理
        }

        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
        
        return $providers;
    }

    /**
     * 根据主体类型获取支持的 Provider
     * 
     * @param string $subjectType 主体类型
     * @param bool $forceReload 强制重新加载
     * @return FeedProviderInterface[]
     */
    public function getProvidersBySubjectType(string $subjectType, bool $forceReload = false): array
    {
        $providers = $this->getProviders($forceReload);
        $filtered = [];
        
        foreach ($providers as $code => $provider) {
            if ($provider->supports($subjectType)) {
                $filtered[$code] = $provider;
            }
        }
        
        return $filtered;
    }

    /**
     * 收集指定主体的 SEO Feed
     * 
     * @param string $subjectType 主体类型
     * @param int $subjectId 主体ID
     * @param array $context 额外上下文
     * @return array Feed 数据数组
     */
    public function collectFeeds(string $subjectType, int $subjectId, array $context = []): array
    {
        $providers = $this->getProvidersBySubjectType($subjectType);
        $feeds = [];
        
        foreach ($providers as $provider) {
            try {
                $feed = $provider->collect(array_merge($context, [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                ]));
                
                if (!empty($feed)) {
                    $feeds[$provider->getCode()] = $feed;
                }
            } catch (\Exception $e) {
                // 忽略错误，继续处理下一个
            }
        }
        
        return $feeds;
    }
}

