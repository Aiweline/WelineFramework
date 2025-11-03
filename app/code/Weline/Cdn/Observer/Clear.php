<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Cdn\Service\CachePurger;

/**
 * CDN缓存清理观察者
 * 
 * 监听 Weline_Cdn::clear 事件并执行缓存清理
 */
class Clear implements ObserverInterface
{
    /**
     * @var CachePurger
     */
    private CachePurger $cachePurger;

    /**
     * 构造函数
     */
    public function __construct(
        CachePurger $cachePurger
    ) {
        $this->cachePurger = $cachePurger;
    }

    /**
     * @DESC          # 执行观察者逻辑
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            // 获取事件数据
            $mode = $event->getData('mode') ?? '';
            $domains = $event->getData('domains') ?? null;
            $adapter = $event->getData('adapter') ?? null;
            
            // 构建模式特定参数
            $params = [];
            if ($mode === 'urls') {
                $params['urls'] = $event->getData('urls') ?? [];
            } elseif ($mode === 'hosts') {
                $params['hosts'] = $event->getData('hosts') ?? [];
            } elseif ($mode === 'tags') {
                $params['tags'] = $event->getData('tags') ?? [];
            } elseif ($mode === 'cache_keys') {
                $params['cache_keys'] = $event->getData('cache_keys') ?? [];
            }

            // 验证模式
            if (empty($mode)) {
                $event->setData('success', false);
                $event->setData('message', __('清理模式不能为空'));
                $event->setData('results', []);
                return;
            }

            // 执行清理
            $result = $this->cachePurger->purge($mode, $domains, $params, $adapter);

            // 将结果回填到事件
            $event->setData('success', $result['success']);
            $event->setData('message', $result['message']);
            $event->setData('results', $result['results']);

        } catch (\Exception $e) {
            // 捕获异常并回填到事件
            $event->setData('success', false);
            $event->setData('message', __('清理失败: %{1}', [$e->getMessage()]));
            $event->setData('results', []);
            $event->setData('error', $e->getMessage());
        }
    }
}

