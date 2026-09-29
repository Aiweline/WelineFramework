<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN规则推送观察者（Cloudflare适配器）
 * 
 * 监听Weline_Cdn::push_rules事件，处理Cloudflare适配器的规则推送
 * 
 * @package Weline_Cdn
 */
class PushRules implements ObserverInterface
{
    public function __construct(private readonly \Weline\Cdn\Service\FpcPolicyManagementService $policies) {}

    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        if (!$domain instanceof Domain) { return; }
        $result = $this->policies->requestManualSync(['domain_id'=>(int)$domain->getId()]);
        $event->setData('result', $result);
        if (!($result['success'] ?? false)) { throw new \RuntimeException((string)$result['message']); }
    }
}
