<?php

namespace Weline\Currency\Observer;

use Weline\Currency\Cache\CurrencyCache;
use Weline\Currency\Model\Currency;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class DetectCurrency implements ObserverInterface
{
    /**
     * @var CurrencyCache 货币缓存实例
     */
    private ?CurrencyCache $cache = null;

    /**
     * 获取缓存实例
     */
    private function getCache(): CurrencyCache
    {
        if ($this->cache === null) {
            $this->cache = new CurrencyCache();
        }
        return $this->cache;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        # 第一个url获取url协议和域名部分
        $uri = $event->getData('uri');
        $split = Url::split_url($uri, 'split');
        if (empty($split)) {
            return;
        }
        $code = $event->getData('code');
        
        // 优化：使用缓存类，避免重复数据库查询
        $cache = $this->getCache();
        $currency = $cache->getByCode($code);
        
        if ($currency !== null && isset($currency['code'])) {
            $event->setData('result', true)
                ->setData('code', $code);
            return;
        }
        
        // 缓存未命中，查询数据库
        if ($currency === null) {
            /** @var Currency $currencyModel */
            $currencyModel = ObjectManager::getInstance(Currency::class);
            $currency = $currencyModel->clear()
                ->where(Currency::fields_CODE, strtoupper($code))
                ->find()
                ->fetch();
            
            if ($currency->getId()) {
                $currencyData = $currency->getData();
                // 保存到缓存
                $cache->setByCode($code, $currencyData);
                $event->setData('result', true)
                    ->setData('code', $code);
            } else {
                // 缓存未找到的结果
                $cache->setByCode($code, null);
            }
        }
    }
}