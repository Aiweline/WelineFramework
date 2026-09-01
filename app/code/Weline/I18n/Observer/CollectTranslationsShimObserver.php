<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Phrase\DictionaryEvents;

/**
 * @deprecated 请改用 Weline_Framework_Phrase::dictionary_register；本 Observer 仅转发 shim。
 */
class CollectTranslationsShimObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $translations = $event->getData('translations');
        if (!is_array($translations) || $translations === []) {
            return;
        }

        $module = $event->getData('module');
        DictionaryEvents::register($translations, is_string($module) ? $module : null);
    }
}
