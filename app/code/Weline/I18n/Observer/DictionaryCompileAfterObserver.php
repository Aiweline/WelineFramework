<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Parser as I18nParser;

/**
 * dictionary_compile_after：编译完成后清理 I18n 侧进程内/缓存状态。
 */
class DictionaryCompileAfterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        I18n::clearLocalWordsCache();

        try {
            w_cache('i18n')->clear();
        } catch (\Throwable) {
        }

        I18nParser::clearWorkerCaches();
    }
}
