<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Block;

use Weline\Framework\View\Block;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentMethodManager;

class PaymentMethod extends Block
{
    private PaymentMethodManager $methodManager;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
    }

    /**
     * 获取支付方式管理器
     * 
     * @return PaymentMethodManager
     */
    public function getMethodManager(): PaymentMethodManager
    {
        return $this->methodManager;
    }

    /**
     * 获取可用的支付方式列表
     *
     * @param array<string, mixed> $context
     * @return array
     */
    public function getAvailableMethods(array $context = []): array
    {
        if (!empty($context['fake_mode'])) {
            $this->methodManager->registerAllProviders();
            $fakeMethod = $this->methodManager->getMethodByCode('fake_card');

            return $fakeMethod ? [$fakeMethod] : [];
        }

        return $this->methodManager->getActiveMethods($context);
    }
}

