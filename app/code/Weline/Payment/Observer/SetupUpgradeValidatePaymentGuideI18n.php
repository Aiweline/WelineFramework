<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentGuideI18nValidator;

/**
 * setup:upgrade 在收集语言包前强制校验支付客户指南 i18n 契约。
 */
class SetupUpgradeValidatePaymentGuideI18n implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!empty($eventData['is_partial_upgrade'])) {
            return;
        }

        /** @var PaymentGuideI18nValidator $validator */
        $validator = ObjectManager::getInstance(PaymentGuideI18nValidator::class);
        $validator->validateOrFail();
    }
}
