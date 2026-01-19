<?php

namespace Weline\I18n\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Cache\LanguageCache;
use Weline\I18n\Model\Locals;

class DetectLanguage implements ObserverInterface
{
    /**
     * @var LanguageCache 语言缓存实例
     */
    private ?LanguageCache $cache = null;

    /**
     * 获取缓存实例
     */
    private function getCache(): LanguageCache
    {
        if ($this->cache === null) {
            $this->cache = new LanguageCache();
        }
        return $this->cache;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /**@var DataObject $data */
        $code = $data->getData('code');
        $codeLower = strtolower($code);
        
        // 优化：使用缓存类，避免重复数据库查询
        $cache = $this->getCache();
        
        // 先检查单个语言代码的缓存
        $checkResult = $cache->checkLanguage($codeLower);
        if ($checkResult !== null) {
            $data->setData('result', $checkResult);
            return;
        }
        
        // 缓存未命中，检查所有语言列表缓存
        $languages = $cache->getAllLanguages();
        if ($languages === null) {
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
            $cache->setAllLanguages($languages);
        }
        
        // 检查语言代码是否存在
        $exists = in_array($codeLower, $languages);
        
        // 保存单个语言代码的检查结果
        $cache->setLanguageCheck($codeLower, $exists);
        
        $data->setData('result', $exists);
    }
}