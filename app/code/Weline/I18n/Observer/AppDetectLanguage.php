<?php

namespace Weline\I18n\Observer;

use Weline\Framework\App\Cache\AppCache;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;

class AppDetectLanguage implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var DataObject $data */
        $data = $event->getData('data');
        $code = $data->getData('code');
        /**@var CacheInterface $cache */
        $cache = ObjectManager::getInstance(AppCache::class . 'Factory');
        $locals = $cache->get('locals');
        if (!$locals) {
            /**@var Locals $local */
            $local = ObjectManager::getInstance(Locals::class);
            $locals = $local
                ->where(Locals::fields_IS_INSTALL, 1)
                ->where(Locals::fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
            foreach ($locals as &$local) {
                $local = $local['code'];
            }
            $cache->set('locals', $locals, 3600 * 24 * 30);
        }
        $data->setData('result',in_array($code, $locals));
    }
}