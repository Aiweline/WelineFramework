<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\AiTranslationQueueService;

/**
 * dictionary_register：登记词条到 I18n DB。
 */
class DictionaryRegisterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $translations = $event->getData('entries') ?? $event->getData('translations');
        if (empty($translations) || !is_array($translations)) {
            return;
        }

        $validatedTranslations = [];
        foreach ($translations as $translation) {
            if (!is_array($translation) || !isset($translation['word'], $translation['translate'])) {
                continue;
            }
            $translation['word'] = Dictionary::assertWord((string)$translation['word']);
            $validatedTranslations[] = $translation;
        }

        if ($validatedTranslations === []) {
            return;
        }

        /** @var Dictionary $dictionary */
        $dictionary = ObjectManager::getInstance(Dictionary::class);
        $created = 0;
        $moduleName = $event->getData('module');

        foreach ($validatedTranslations as $translation) {
            $translationKey = (string)$translation['word'];
            $value = (string)$translation['translate'];
            $entryModule = $translation['module'] ?? (is_string($moduleName) ? $moduleName : 'Weline_I18n');

            $dictionary->load(Dictionary::schema_fields_WORD, $translationKey);
            if (!$dictionary->getId()) {
                $dictionary->setData(Dictionary::schema_fields_WORD, $translationKey);
                $dictionary->setData(Dictionary::schema_fields_MODULE, $entryModule);
                $dictionary->setData(Dictionary::schema_fields_IS_BACKEND, $translation['is_backend'] ?? 1);
                $dictionary->save();
                $created++;
            }

            $defaultLocale = $translation['locale'] ?? 'zh_Hans_CN';
            /** @var LocaleDictionary $localeDict */
            $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
            $md5 = LocaleDictionary::generateMd5($translationKey, $defaultLocale);
            $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
            if (!$localeDict->getId()) {
                $localeDict->setData(LocaleDictionary::schema_fields_MD5, $md5);
                $localeDict->setData(LocaleDictionary::schema_fields_WORD, $translationKey);
                $localeDict->setData(LocaleDictionary::schema_fields_LOCALE_CODE, $defaultLocale);
                $localeDict->setData(LocaleDictionary::schema_fields_TRANSLATE, $value);
                $localeDict->save();
            }
        }

        if ($created > 0) {
            try {
                ObjectManager::getInstance(AiTranslationQueueService::class)
                    ->enqueueEnabledLocales('dictionary_register_event');
            } catch (\Throwable $throwable) {
                w_log_error('I18n AI translation enqueue failed: ' . $throwable->getMessage(), [], 'i18n');
            }
        }
    }
}
