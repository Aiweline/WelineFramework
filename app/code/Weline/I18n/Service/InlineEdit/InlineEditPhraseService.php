<?php

declare(strict_types=1);

namespace Weline\I18n\Service\InlineEdit;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\DictionaryAreaPolicy;

/**
 * Operator phrase save for storefront inline edit (session-gated).
 */
final class InlineEditPhraseService
{
    /**
     * @return array{ok:bool,message:string,translate?:string,locale?:string}
     */
    public function save(string $word, string $translate, string $localeCode): array
    {
        $word = InlinePhraseMarker::unwrapPlain(\trim($word));
        // Never persist operator markers into the dictionary (causes nested [[W18N:]] leaks).
        $translate = InlinePhraseMarker::unwrapPlain((string)$translate);
        $localeCode = \trim(\str_replace('-', '_', $localeCode));
        if ($word === '' || $localeCode === '') {
            return ['ok' => false, 'message' => InlinePhraseMarker::plain('参数不完整')];
        }
        try {
            $word = WordDictionary::assertWord($word);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        try {
            /** @var WordDictionary $dictionary */
            $dictionary = ObjectManager::getInstance(WordDictionary::class);
            $existing = clone $dictionary;
            $existing->clear()
                ->where(WordDictionary::schema_fields_WORD, $word)
                ->find()
                ->fetch();
            if (!(int)$existing->getId()) {
                $dictionary->reset()->setData([
                    WordDictionary::schema_fields_WORD => $word,
                    WordDictionary::schema_fields_AREA => DictionaryAreaPolicy::AREA_FRONTEND,
                    WordDictionary::schema_fields_IS_BACKEND => 0,
                    WordDictionary::schema_fields_MODULE => 'Weline_I18n',
                ])->save();
            }

            /** @var LocaleDictionary $localeDictionary */
            $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
            if (!$localeDictionary->upsert($word, $localeCode, $translate)) {
                return ['ok' => false, 'message' => InlinePhraseMarker::plain('保存失败')];
            }

            /** @var AiTranslationPublisher $publisher */
            $publisher = ObjectManager::getInstance(AiTranslationPublisher::class);
            $publisher->publishLocale($localeCode);

            return [
                'ok' => true,
                'message' => InlinePhraseMarker::plain('保存成功'),
                'translate' => $translate,
                'locale' => $localeCode,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
