<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Model\FaqItem;
use Weline\Framework\Manager\ObjectManager;

/**
 * Idempotent ecommerce FAQ template pack seeds (website_id=0 global defaults).
 * Seeds every default-website language that has maintained copy in FaqSeedCopyCatalog
 * (at least zh_Hans_CN + en_US) so storefront locales do not fall back incorrectly.
 */
final class FaqTemplateSeedService
{
    public const LOCALE_ZH = 'zh_Hans_CN';
    public const LOCALE_EN = 'en_US';

    /** @return list<string> */
    public static function requiredLocales(): array
    {
        $wanted = FaqSeedLocaleResolver::forDefaultWebsite();
        $maintained = array_fill_keys(FaqSeedCopyCatalog::maintainedLocales(), true);
        $out = [];
        foreach ($wanted as $locale) {
            if (isset($maintained[$locale])) {
                $out[] = $locale;
            }
        }
        if ($out === []) {
            return [self::LOCALE_ZH, self::LOCALE_EN];
        }

        return $out;
    }

    public function __construct(
        private readonly FaqService $faqs,
    ) {
    }

    public function seedAll(): int
    {
        $created = 0;
        foreach (FaqTemplatePacks::codes() as $pack) {
            $created += $this->seedPack($pack);
        }

        return $created;
    }

    public function seedPack(string $pack): int
    {
        $pack = FaqTemplatePacks::normalize($pack);
        $created = 0;
        foreach (self::requiredLocales() as $locale) {
            $created += $this->seedPackLocale($pack, $locale);
        }

        return $created;
    }

    /**
     * Relabel legacy empty-locale Chinese seeds as zh_Hans_CN so en_US no longer inherits them.
     */
    public function migrateEmptyLocaleToZhHans(): int
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $updated = 0;
        try {
            $rows = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, TemplateFaqTypeProvider::TYPE_CODE)
                ->where(FaqItem::schema_fields_WEBSITE_ID, 0)
                ->where(FaqItem::schema_fields_STORE_CODE, '')
                ->where(FaqItem::schema_fields_CHANNEL_CODE, '')
                ->where(FaqItem::schema_fields_LOCALE_CODE, '')
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return 0;
            }
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[FaqItem::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $pack = (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? '');
                $faqKey = (string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '');
                if ($pack === '' || $faqKey === '') {
                    continue;
                }
                if ($this->exists($pack, $faqKey, self::LOCALE_ZH)) {
                    // Prefer the explicit zh row; drop the legacy empty duplicate.
                    $model->clear()->load($id);
                    if ((int)$model->getData(FaqItem::schema_fields_ID) === $id) {
                        $model->delete();
                        $updated++;
                    }
                    continue;
                }
                $model->clear()->load($id);
                if ((int)$model->getData(FaqItem::schema_fields_ID) !== $id) {
                    continue;
                }
                $model->setData(FaqItem::schema_fields_LOCALE_CODE, self::LOCALE_ZH);
                $model->setData(FaqItem::schema_fields_UPDATED_AT, $now);
                $model->save();
                $updated++;
            }
        } catch (\Throwable) {
            return $updated;
        }

        return $updated;
    }

    private function seedPackLocale(string $pack, string $locale): int
    {
        $defs = FaqSeedCopyCatalog::templatePack($pack)[$locale] ?? [];
        if ($defs === []) {
            return 0;
        }
        $created = 0;
        $sort = 0;
        foreach ($defs as $row) {
            $faqKey = (string)$row['faq_key'];
            if ($this->exists($pack, $faqKey, $locale)) {
                $sort++;
                continue;
            }
            $this->faqs->save([
                'website_id' => 0,
                'store_code' => '',
                'channel_code' => '',
                'locale_code' => $locale,
                'type_code' => TemplateFaqTypeProvider::TYPE_CODE,
                'entity_uuid' => $pack,
                'faq_key' => $faqKey,
                'question' => (string)$row['question'],
                'answer' => (string)$row['answer'],
                'sort_order' => $sort++,
                'status' => FaqItem::STATUS_ENABLED,
            ]);
            $created++;
        }

        return $created;
    }

    private function exists(string $pack, string $faqKey, string $localeCode): bool
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        try {
            $row = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, TemplateFaqTypeProvider::TYPE_CODE)
                ->where(FaqItem::schema_fields_ENTITY_UUID, $pack)
                ->where(FaqItem::schema_fields_WEBSITE_ID, 0)
                ->where(FaqItem::schema_fields_STORE_CODE, '')
                ->where(FaqItem::schema_fields_CHANNEL_CODE, '')
                ->where(FaqItem::schema_fields_LOCALE_CODE, $localeCode)
                ->where(FaqItem::schema_fields_FAQ_KEY, $faqKey)
                ->find()
                ->fetch();
            if (is_object($row) && method_exists($row, 'getData')) {
                return (int)$row->getData(FaqItem::schema_fields_ID) > 0;
            }
            if (is_array($row)) {
                return (int)($row[FaqItem::schema_fields_ID] ?? 0) > 0;
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
