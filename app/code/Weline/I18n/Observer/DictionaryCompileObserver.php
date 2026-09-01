<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Model\I18n;

/**
 * dictionary_compile 增强：源码扫描 merge + online DB 合并。
 */
class DictionaryCompileObserver implements ObserverInterface
{
    public function __construct(
        private readonly I18n $i18n,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $localsWords = &$data['locals_words'];
        $wordsByModule = &$data['words_by_module'];
        $sourceTranslations = &$data['source_translations'];
        if (!is_array($localsWords) || !is_array($wordsByModule) || !is_array($sourceTranslations)) {
            return;
        }

        $moduleName = isset($data['module']) && is_string($data['module']) ? $data['module'] : null;
        $this->i18n->enrichDictionaryCompile($localsWords, $wordsByModule, $sourceTranslations, $moduleName);
        $event->setData($data);
    }
}
