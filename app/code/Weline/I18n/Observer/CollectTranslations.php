<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 收集翻译词观察者
 * 监听 Weline_I18n::collect_translations 事件，将翻译词存储到I18n字典表中
 */
class CollectTranslations implements ObserverInterface
{
    /**
     * 收集翻译词并存储到I18n Dictionary
     * 
     * 事件数据格式：
     * [
     *     'translations' => [
     *         ['word' => '翻译键', 'translate' => '翻译值'],
     *         ...
     *     ]
     * ]
     */
    public function execute(Event &$event): void
    {
        $translations = $event->getData('translations');

        if (empty($translations) || !is_array($translations)) {
            return;
        }

        /** @var Dictionary $dictionary */
        $dictionary = ObjectManager::getInstance(Dictionary::class);

        foreach ($translations as $translation) {
            if (!isset($translation['word']) || !isset($translation['translate'])) {
                continue;
            }

            $translationKey = $translation['word'];
            $value = $translation['translate'];
            
            // 从事件数据中获取模块名（如果提供）
            $moduleName = $translation['module'] ?? $event->getData('module') ?? 'Weline_I18n';

            // 检查词汇是否已存在于i18n_dictionary表
            $dictionary->load($translationKey, Dictionary::fields_WORD);
            if (!$dictionary->getId()) {
                $dictionary->setData(Dictionary::fields_WORD, $translationKey);
                $dictionary->setData(Dictionary::fields_MODULE, $moduleName);
                $dictionary->setData(Dictionary::fields_IS_BACKEND, $translation['is_backend'] ?? 1);
                $dictionary->save();
            }
            
            // 保存默认语言的翻译（中文）
            $defaultLocale = $translation['locale'] ?? 'zh_Hans_CN';
            /** @var LocaleDictionary $localeDict */
            $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
            $md5 = LocaleDictionary::generateMd5($translationKey, $defaultLocale);
            $localeDict->load($md5, LocaleDictionary::fields_MD5);
            
            if (!$localeDict->getId()) {
                $localeDict->setData(LocaleDictionary::fields_MD5, $md5);
                $localeDict->setData(LocaleDictionary::fields_WORD, $translationKey);
                $localeDict->setData(LocaleDictionary::fields_LOCALE_CODE, $defaultLocale);
                $localeDict->setData(LocaleDictionary::fields_TRANSLATE, $value);
                $localeDict->save();
            }
        }
    }
}

