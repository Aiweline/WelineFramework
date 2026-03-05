<?php

namespace Weline\Currency\Observer;

use Weline\Currency\Model\Currency;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class DetectCurrency implements ObserverInterface
{
    private const CACHE_KEY_PREFIX = 'currency_code_';

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
        $codeUpper = strtoupper($code);
        
        $cache = w_cache('currency');
        $cacheKey = self::CACHE_KEY_PREFIX . $codeUpper;
        
        // 优化：使用缓存，避免重复数据库查询
        $currency = $cache->get($cacheKey);
        
        if ($currency !== false && is_array($currency) && isset($currency['code'])) {
            $event->setData('result', true)
                ->setData('code', $code);
            return;
        }
        
        // 缓存未命中，查询数据库
        if ($currency === false) {
            /** @var Currency $currencyModel */
            $currencyModel = ObjectManager::getInstance(Currency::class);
            $currency = $currencyModel->clear()
                ->where(Currency::schema_fields_CODE, $codeUpper)
                ->find()
                ->fetch();
            
            if ($currency->getId()) {
                $currencyData = $currency->getData();
                // 保存到缓存
                $cache->set($cacheKey, $currencyData);
                $event->setData('result', true)
                    ->setData('code', $code);
            } else {
                // 缓存未找到的结果（空数组表示不存在）
                $cache->set($cacheKey, []);
            }
        }
    }
}