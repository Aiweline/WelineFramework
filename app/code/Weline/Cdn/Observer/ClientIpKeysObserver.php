<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 客户端 IP Keys 观察者
 *
 * 监听 Weline_Framework_Http::integration::client_ip_keys 事件，
 * 分发到所有 CDN 适配器收集真实 IP Header keys，有返回值则合并。
 *
 * 符合 DIP：本类不依赖具体 CDN，由各适配器自行返回其 keys。
 */
class ClientIpKeysObserver implements ObserverInterface
{
    public function __construct(
        private readonly AdapterResolver $adapterResolver
    ) {
    }

    public function execute(Event &$event): void
    {
        $keys = $event->getData('keys') ?? [];
        if (!is_array($keys)) {
            return;
        }

        $cdnKeys = [];
        foreach ($this->adapterResolver->getAllAdapters() as $adapter) {
            if (!$adapter instanceof AdapterInterface) {
                continue;
            }
            $adapterKeys = $adapter->getRealIpHeaderKeys();
            if (!empty($adapterKeys) && is_array($adapterKeys)) {
                foreach ($adapterKeys as $k) {
                    if (is_string($k) && $k !== '' && !in_array($k, $cdnKeys, true)) {
                        $cdnKeys[] = $k;
                    }
                }
            }
        }

        if (empty($cdnKeys)) {
            return;
        }

        foreach (array_reverse($cdnKeys) as $cdnKey) {
            array_unshift($keys, $cdnKey);
        }
        $event->setData('keys', $keys);
    }
}
