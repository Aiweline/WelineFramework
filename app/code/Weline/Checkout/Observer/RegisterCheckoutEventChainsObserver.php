<?php

declare(strict_types=1);

namespace Weline\Checkout\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 向 Visitor 事件链系统注册结账形态漏斗（代付 / 分享 / 快捷购买 / 快捷支付）。
 *
 * 过程事件由前台标记；最后一步均为 checkout_success；complete_event 按形态区分。
 */
final class RegisterCheckoutEventChainsObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $chains = $event->getData('chains');
        if (!\is_array($chains)) {
            $chains = [];
        }
        foreach ($this->definitions() as $chain) {
            $chains[] = $chain;
        }
        $event->setData('chains', $chains);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        $owner = 'Weline_Checkout';

        return [
            [
                'id' => 'checkout_friend_help_pay',
                'name' => '找朋友代付结账',
                'owner' => $owner,
                'complete_event' => 'friend_help_pay_checkout_success',
                'steps' => [
                    ['type' => 'track', 'event' => 'friend_help_pay', 'label' => '发起代付'],
                    ['type' => 'track', 'event' => 'friend_help_pay_link_ready', 'label' => '代付链接已生成'],
                    ['type' => 'track', 'event' => 'checkout_success', 'label' => '结账成功'],
                ],
            ],
            [
                'id' => 'checkout_selection_share',
                'name' => '分享规格结账',
                'owner' => $owner,
                'complete_event' => 'selection_share_checkout_success',
                'steps' => [
                    ['type' => 'track', 'event' => 'selection_share', 'label' => '分享给朋友'],
                    ['type' => 'track', 'event' => 'selection_share_link_ready', 'label' => '分享链接已生成'],
                    ['type' => 'track', 'event' => 'checkout_success', 'label' => '结账成功'],
                ],
            ],
            [
                'id' => 'checkout_quick_buy',
                'name' => '快捷购买结账',
                'owner' => $owner,
                'complete_event' => 'quick_buy_checkout_success',
                'steps' => [
                    ['type' => 'track', 'event' => 'quick_buy', 'label' => '快捷购买'],
                    ['type' => 'track', 'event' => 'quick_buy_checkout_ready', 'label' => '快捷购买待支付'],
                    ['type' => 'track', 'event' => 'checkout_success', 'label' => '结账成功'],
                ],
            ],
            [
                'id' => 'checkout_express_pay',
                'name' => '快捷支付结账',
                'owner' => $owner,
                'complete_event' => 'express_pay_checkout_success',
                'steps' => [
                    ['type' => 'track', 'event' => 'express_pay', 'label' => '快捷支付'],
                    ['type' => 'track', 'event' => 'express_pay_started', 'label' => '已拉起支付'],
                    ['type' => 'track', 'event' => 'checkout_success', 'label' => '结账成功'],
                ],
            ],
        ];
    }
}
