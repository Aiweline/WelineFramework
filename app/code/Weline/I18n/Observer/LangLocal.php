<?php

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

class LangLocal implements ObserverInterface
{

    public function execute(Event $event)
    {
        $data = $event->getData('data');
        $lang = $data->getData('lang');
        /** @var I18n $I18n */
        $I18n = ObjectManager::getInstance(I18n::class);
        $data->setData('lang_local', $I18n->getLocalByCode($lang));
    }
}