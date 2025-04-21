<?php

namespace Weline\Currency\Observer;

use Weline\Currency\Model\Currency;
use Weline\Framework\App\Debug;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;

class DetectCurrency implements ObserverInterface
{

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        # 第一个url获取url协议和域名部分
        $uri = $event->getData('uri');
        $split = Url::split_url($uri, 'split');
        if (empty($split)) {
            return;
        }
        $code = $event->getData('code');
        # 网站模型
        /** @var Currency $currency_model */
        $currency_model = w_obj(Currency::class);
        /** @var Currency $site */
        $currency = $currency_model
            ->where('code', strtoupper($code))
            ->find()
            ->fetch();
        if ($currency->getId()) {
            $event->setData('result', true)
                ->setData('code', $code);
        }
    }
}