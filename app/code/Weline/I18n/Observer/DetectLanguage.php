<?php

namespace Weline\I18n\Observer;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;

class DetectLanguage implements ObserverInterface
{
    private const CACHE_KEY_ALL_LANGUAGES = 'all_languages_list';
    private const CACHE_KEY_PREFIX_CHECK = 'lang_check_';

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /**@var DataObject $data */
        $code = $data->getData('code');
        $codeLower = strtolower($code);
        
        $cache = w_cache('i18n');
        
        // 先检查单个语言代码的缓存
        $checkCacheKey = self::CACHE_KEY_PREFIX_CHECK . $codeLower;
        $checkResult = $cache->get($checkCacheKey);
        if ($checkResult !== false) {
            $data->setData('result', (bool)$checkResult);
            return;
        }
        
        // 缓存未命中，检查所有语言列表缓存
        $languages = $cache->get(self::CACHE_KEY_ALL_LANGUAGES);
        if ($languages === false || !is_array($languages)) {
            // 查询数据库
            /**@var Locals $local */
            $local = ObjectManager::getInstance(Locals::class);
            $locals = $local
                ->where(Locals::fields_IS_INSTALL, 1)
                ->where(Locals::fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
            
            $languages = [];
            foreach ($locals as $localData) {
                $languages[] = strtolower($localData['code']);
            }
            
            // 保存到缓存
            $cache->set(self::CACHE_KEY_ALL_LANGUAGES, $languages);
        }
        
        // 检查语言代码是否存在
        $exists = in_array($codeLower, $languages);
        
        // 保存单个语言代码的检查结果
        $cache->set($checkCacheKey, $exists ? 1 : 0);
        
        $data->setData('result', $exists);
    }
}