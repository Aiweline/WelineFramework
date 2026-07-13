<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\I18n\Model\Locale\Dictionary;

final class GlobalDictionaryProvider implements GlobalDictionaryProviderInterface
{
    public function words(string $locale): array
    {
        $rows = ObjectManager::getInstance(Dictionary::class)->reset()
            ->where(Dictionary::schema_fields_LOCALE_CODE, $locale)
            ->where(Dictionary::schema_fields_TRANSLATE, '', '!=')
            ->select()
            ->fetchArray();
        $words = [];
        foreach ($rows as $row) {
            $word = $row[Dictionary::schema_fields_WORD] ?? '';
            $translate = $row[Dictionary::schema_fields_TRANSLATE] ?? '';
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }
        return $words;
    }
}
