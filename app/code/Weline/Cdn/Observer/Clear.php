<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN缓存清理观察者
 * 
 * 监听 Weline_Cdn::clear 事件，执行缓存清理
 * 
 * @package Weline_Cdn
 */
class Clear implements ObserverInterface
{
    private CachePurger $cachePurger;

    public function __construct(CachePurger $cachePurger)
    {
        $this->cachePurger = $cachePurger;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        // 验证必需参数
        if (empty($data['domain'])) {
            $event->setData('result', [
                'success' => false,
                'message' => __('域名参数不能为空')
            ]);
            return;
        }

        $mode = $data['mode'] ?? 'everything';
        $modeData = $data['data'] ?? $data;
        if (!isset($data['data']) && is_array($modeData)) {
            unset($modeData['domain'], $modeData['mode'], $modeData['name'], $modeData['observers'], $modeData['result']);
        }

        try {
            $result = $this->cachePurger->purge($data['domain'], $mode, $modeData);
            
            $event->setData('result', $result);
        } catch (\Exception $e) {
            $event->setData('result', [
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

