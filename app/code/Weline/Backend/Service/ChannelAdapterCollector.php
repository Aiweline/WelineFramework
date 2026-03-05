<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 渠道适配器收集服务
 *
 * 从 extends 注册表获取 ChannelAdapterInterface 实现并实例化。
 * 不使用 ObjectManager::getInstances()，因其返回所有已缓存实例，会误含非适配器类型。
 */
class ChannelAdapterCollector
{
    private static ?array $adapters = null;

    /**
     * 获取所有渠道适配器实例
     *
     * @return ChannelAdapterInterface[]
     */
    public function getAdapters(): array
    {
        if (self::$adapters !== null) {
            return self::$adapters;
        }

        $extendsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'extends.php';
        if (!is_file($extendsFile)) {
            return self::$adapters = [];
        }

        $config = include $extendsFile;
        $implClasses = $config[ChannelAdapterInterface::class] ?? [];

        if (!is_array($implClasses)) {
            return self::$adapters = [];
        }

        $adapters = [];
        foreach ($implClasses as $implClass) {
            if (is_string($implClass) && class_exists($implClass)) {
                $adapter = ObjectManager::getInstance($implClass);
                if ($adapter instanceof ChannelAdapterInterface) {
                    $adapters[] = $adapter;
                }
            }
        }

        return self::$adapters = $adapters;
    }

    /**
     * 根据渠道代码获取适配器
     */
    public function getAdapterByCode(string $channelCode): ?ChannelAdapterInterface
    {
        foreach ($this->getAdapters() as $adapter) {
            if ($adapter->getChannelCode() === $channelCode) {
                return $adapter;
            }
        }
        return null;
    }
}
